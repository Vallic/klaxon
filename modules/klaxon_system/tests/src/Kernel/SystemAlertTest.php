<?php

declare(strict_types=1);

namespace Drupal\Tests\klaxon_system\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\klaxon\Message;
use Drupal\klaxon\Reading;
use Drupal\klaxon\Entity\Alert;
use Drupal\klaxon\Entity\Channel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers the alerts about the site itself.
 */
#[Group('klaxon')]
#[RunTestsInSeparateProcesses]
class SystemAlertTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'dblog', 'klaxon', 'klaxon_test', 'klaxon_system'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('dblog', ['watchdog']);
    $this->installSchema('klaxon', ['klaxon_state', 'klaxon_ledger']);
    $this->installConfig(['klaxon']);

    Channel::create([
      'id' => 'c',
      'label' => 'Test channel',
      'status' => TRUE,
      'transport' => ['id' => 'failing', 'mode' => 'ok'],
    ])->save();
  }

  /**
   * Cron that finished recently says nothing.
   */
  public function testCronThatFinishedRecently(): void {
    $this->makeAlert('cron', ['id' => 'system_cron', 'hours' => 6]);
    $this->container->get('state')->set('system.cron_last', $this->now() - 3600);

    $this->assertSame(3600, $this->reading('cron')->value, 'Measured in seconds behind.');
    $this->assertNull($this->evaluate('cron'), 'An hour ago is fine.');
  }

  /**
   * Cron that has not finished for longer than allowed does not.
   */
  public function testCronThatFellBehind(): void {
    $this->makeAlert('cron', ['id' => 'system_cron', 'hours' => 6]);
    $this->container->get('state')->set('system.cron_last', $this->now() - (8 * 3600));

    $message = $this->evaluate('cron');

    $this->assertNotNull($message, 'Eight hours is past six.');
    $this->assertArrayHasKey('Behind by', $message->facts, 'The message says how far behind.');
  }

  /**
   * Cron that has never finished counts as behind, not as fine.
   */
  public function testCronThatNeverRan(): void {
    $this->makeAlert('cron', ['id' => 'system_cron', 'hours' => 6]);
    $this->container->get('state')->delete('system.cron_last');

    $message = $this->evaluate('cron');

    $this->assertNotNull($message);
    $this->assertSame('never', $message->facts['Last finished run']);
  }

  /**
   * The hours asked for become the threshold that is actually tested.
   */
  public function testTheHoursBecomeTheThreshold(): void {
    $this->makeAlert('cron', ['id' => 'system_cron', 'hours' => 2]);
    $config = Alert::load('cron')->getType()->getConfiguration();

    $this->assertSame(7200, $config['value'], 'Two hours, in seconds.');
    $this->assertSame('>', $config['operator']);
  }

  /**
   * Errors are counted by severity and window, and broken down by channel.
   */
  public function testLoggedErrors(): void {
    $this->log('php', RfcLogLevel::ERROR, 12);
    $this->log('cron', RfcLogLevel::ERROR, 3);
    // Too mild, and too old, respectively.
    $this->log('php', RfcLogLevel::NOTICE, 40);
    $this->log('php', RfcLogLevel::ERROR, 5, $this->now() - 7200);

    $this->makeAlert('errors', [
      'id' => 'system_errors',
      'severity' => RfcLogLevel::ERROR,
      'minutes' => 60,
      'operator' => '>',
      'value' => 10,
    ]);

    $reading = $this->reading('errors');

    $this->assertSame(15, $reading->value, 'Only recent entries at or above the severity.');
    $this->assertStringContainsString('php (12)', $reading->context['Channels']);
    $this->assertStringContainsString('cron (3)', $reading->context['Channels']);
    $this->assertNotNull($this->evaluate('errors'));
  }

  /**
   * Narrowing to a channel is what makes a low threshold meaningful.
   */
  public function testErrorsInOneChannel(): void {
    $this->log('php', RfcLogLevel::ERROR, 30);
    $this->log('payment', RfcLogLevel::ERROR, 2);

    $this->makeAlert('payments', [
      'id' => 'system_errors',
      'severity' => RfcLogLevel::ERROR,
      'channels' => ['payment'],
      'minutes' => 60,
      'operator' => '>',
      'value' => 1,
    ]);

    $this->assertSame(2, $this->reading('payments')->value, 'The other channel is not counted.');
  }

  /**
   * The most recent entry is rendered with its placeholders filled in.
   */
  public function testTheMostRecentErrorIsReadable(): void {
    $this->container->get('database')->insert('watchdog')->fields([
      'uid' => 0,
      'type' => 'php',
      'message' => 'Gateway %name refused the payment: @reason.',
      'variables' => serialize(['%name' => 'Example', '@reason' => 'timeout']),
      'severity' => RfcLogLevel::ERROR,
      'link' => '',
      'location' => 'http://example.com',
      'referer' => '',
      'hostname' => '127.0.0.1',
      'timestamp' => $this->now(),
    ])->execute();

    $this->makeAlert('errors', [
      'id' => 'system_errors',
      'severity' => RfcLogLevel::ERROR,
      'minutes' => 60,
      'operator' => '>',
      'value' => 0,
    ]);

    $this->assertSame(
      'Gateway Example refused the payment: timeout.',
      $this->reading('errors')->context['Most recent'],
      'Placeholders filled in, and the markup a %placeholder adds taken back out.',
    );
  }

  /**
   * Only the queues that are actually behind are named.
   */
  public function testQueueBacklog(): void {
    $this->fillQueue('klaxon_delivery', 12);
    $this->fillQueue('cron', 2);

    $this->makeAlert('queues', ['id' => 'system_queue', 'limit' => 5]);

    $rows = $this->reading('queues')->rows;

    $this->assertSame(['klaxon_delivery'], array_keys($rows), 'The quiet queue is not mentioned.');
    $this->assertSame(12, $rows['klaxon_delivery']['waiting']);
    $this->assertNotNull($this->evaluate('queues'));
  }

  /**
   * Queues below the line say nothing at all.
   */
  public function testQueuesUnderTheLineStayQuiet(): void {
    $this->fillQueue('klaxon_delivery', 3);

    $this->makeAlert('queues', ['id' => 'system_queue', 'limit' => 5]);

    $this->assertSame([], $this->reading('queues')->rows);
    $this->assertNull($this->evaluate('queues'));
  }

  /**
   * Watching one queue means the others cannot set it off.
   */
  public function testWatchingOneQueue(): void {
    $this->fillQueue('cron', 50);

    $this->makeAlert('queues', [
      'id' => 'system_queue',
      'queues' => ['klaxon_delivery'],
      'limit' => 5,
    ]);

    $this->assertSame([], $this->reading('queues')->rows);
  }

  /**
   * Every shipped type saves, reloads and describes itself.
   */
  public function testEveryTypeRoundTrips(): void {
    foreach ([
      'system_cron' => ['hours' => 3],
      'system_errors' => ['severity' => RfcLogLevel::WARNING, 'minutes' => 30],
      'system_queue' => ['limit' => 20],
    ] as $id => $config) {
      $this->makeAlert($id, ['id' => $id] + $config);
      $type = Alert::load($id)->getType();

      $this->assertSame($id, $type->getPluginId());
      $this->assertNotSame('', $type->summary());
    }
  }

  /**
   * Writes a number of log entries into one channel.
   */
  protected function log(string $channel, int $severity, int $count, ?int $timestamp = NULL): void {
    for ($i = 0; $i < $count; $i++) {
      $this->container->get('database')->insert('watchdog')->fields([
        'uid' => 0,
        'type' => $channel,
        'message' => 'Something went wrong.',
        'variables' => serialize([]),
        'severity' => $severity,
        'link' => '',
        'location' => 'http://example.com',
        'referer' => '',
        'hostname' => '127.0.0.1',
        'timestamp' => $timestamp ?? $this->now(),
      ])->execute();
    }
  }

  /**
   * Puts a number of items into a queue.
   */
  protected function fillQueue(string $name, int $count): void {
    $queue = $this->container->get('queue')->get($name);
    $queue->createQueue();

    for ($i = 0; $i < $count; $i++) {
      $queue->createItem(['n' => $i]);
    }
  }

  /**
   * Creates an enabled alert of one type, delivering to the test channel.
   */
  protected function makeAlert(string $id, array $type): void {
    Alert::create([
      'id' => $id,
      'label' => $id,
      'status' => TRUE,
      'type' => $type,
      'channels' => ['c'],
      'notify_on' => 'every',
      'subject' => $id,
    ])->save();
  }

  /**
   * Evaluates one alert and returns whatever it decided to say.
   */
  protected function evaluate(string $id): ?Message {
    return $this->container->get('klaxon.runner')->run(Alert::load($id));
  }

  /**
   * What one alert measures, without the notification policy in the way.
   */
  protected function reading(string $id): Reading {
    return Alert::load($id)->getType()->read();
  }

  /**
   * The request time every fixture is relative to.
   */
  protected function now(): int {
    return $this->container->get('datetime.time')->getRequestTime();
  }

}
