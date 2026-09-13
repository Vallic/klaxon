<?php

declare(strict_types=1);

namespace Drupal\Tests\klaxon\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Core\Queue\SuspendQueueException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\klaxon\Alert\Deliverer;
use Drupal\klaxon\Entity\Alert;
use Drupal\klaxon\Entity\Channel;
use Drupal\klaxon_test\Plugin\Klaxon\Transport\Failing;

/**
 * Covers queued delivery: retries, giving up, and escalation.
 */
#[Group('klaxon')]
#[RunTestsInSeparateProcesses]
class DeliveryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'klaxon', 'klaxon_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('klaxon', ['klaxon_state', 'klaxon_ledger']);
    $this->installConfig(['klaxon']);
  }

  /**
   * Firing an alert queues the message instead of sending it.
   */
  public function testFireQueuesRatherThanSending(): void {
    $this->makeAlert('ok');

    $this->assertTrue($this->dispatcher()->fire('a'));
    $this->assertSame(1, $this->queue()->numberOfItems(), 'One job was queued.');
    $this->assertSame(0, $this->attempts(), 'The transport was not touched yet.');

    $this->processQueue();

    $this->assertSame(1, $this->attempts());
    $this->assertSame(['Something happened'], $this->delivered());
    $this->assertSame(0, $this->queue()->numberOfItems(), 'The job was consumed.');
  }

  /**
   * A job survives a queue backend that does not keep PHP objects.
   *
   * The core database queue serializes, so objects come back as objects and
   * this is easy to get wrong without noticing. RabbitMQ and several other
   * backends encode as JSON, and anything that only works with the first kind
   * is an alert silently discarded on sites running the second.
   */
  public function testJobSurvivesQueueThatDropsObjects(): void {
    $this->makeAlert('ok');
    $this->dispatcher()->fire('a');

    $item = $this->queue()->claimItem();
    $this->assertIsObject($item);

    // What a JSON-encoding backend would hand back.
    $data = json_decode((string) json_encode($item->data), TRUE);

    $this->container->get('plugin.manager.queue_worker')
      ->createInstance(Deliverer::QUEUE)
      ->processItem($data);

    $this->assertSame(1, $this->attempts(), 'The transport was actually called.');
    $this->assertSame(['Something happened'], $this->delivered());
  }

  /**
   * Nothing object-shaped is put on the queue in the first place.
   */
  public function testTheQueuedJobIsPlainData(): void {
    $this->makeAlert('ok');
    $this->dispatcher()->fire('a');

    $item = $this->queue()->claimItem();
    $this->assertIsObject($item);

    $this->assertIsArray($item->data['message'], 'The message travels as data.');
    $this->assertSame('Something happened', $item->data['message']['subject']);
    $this->assertSame($item->data, json_decode((string) json_encode($item->data), TRUE), 'And it is unchanged by an encode.');
  }

  /**
   * Run now bypasses the queue so an editor sees the result immediately.
   */
  public function testFireNowBypassesTheQueue(): void {
    $this->makeAlert('ok');

    $this->assertTrue($this->dispatcher()->fireNow('a'));
    $this->assertSame(1, $this->attempts());
    $this->assertSame(0, $this->queue()->numberOfItems(), 'Nothing was queued.');
  }

  /**
   * A failure retrying cannot fix is not retried.
   */
  public function testPermanentFailureIsNotRetried(): void {
    $this->makeAlert('permanent');

    $this->dispatcher()->fire('a');
    $this->processQueue();

    $this->assertSame(1, $this->attempts(), 'It was tried exactly once.');
    $this->assertSame(0, $this->queue()->numberOfItems(), 'Nothing was requeued.');
  }

  /**
   * A retryable failure comes back, held off rather than retried instantly.
   */
  public function testTransientFailureWaitsBeforeRetrying(): void {
    $this->config('klaxon.settings')->set('max_attempts', 3)->save();
    $this->makeAlert('transient');

    $this->dispatcher()->fire('a');
    $this->processQueue();

    $this->assertSame(1, $this->attempts(), 'Cron must not burn every attempt in one pass.');
    $this->assertSame(1, $this->queue()->numberOfItems(), 'The retry is waiting.');
    $this->assertFalse($this->queue()->claimItem(), 'And it is not claimable yet.');
  }

  /**
   * The last allowed attempt gives up instead of queueing another.
   */
  public function testGivingUpAfterTheLastAttempt(): void {
    $this->config('klaxon.settings')->set('max_attempts', 3)->save();
    $this->makeAlert('transient');

    // Stand in for a job that has already used its first two attempts.
    $this->dispatcher()->fire('a');
    $item = $this->queue()->claimItem();
    $data = $item->data;
    $data['attempt'] = 3;
    $this->queue()->deleteItem($item);
    $this->queue()->createItem($data);

    $this->processQueue();

    $this->assertSame(1, $this->attempts(), 'It tried once more.');
    $this->assertSame(0, $this->queue()->numberOfItems(), 'Then stopped rather than looping.');
  }

  /**
   * Being asked to slow down suspends the queue without losing the job.
   */
  public function testRateLimitSuspendsAndKeepsTheJob(): void {
    $this->makeAlert('rate_limit');

    $this->dispatcher()->fire('a');
    $this->processQueue();

    $this->assertSame(1, $this->attempts());
    $this->assertSame(1, $this->queue()->numberOfItems(), 'The job survived the suspension.');
  }

  /**
   * When delivery is lost for good, the fallback channel is told.
   */
  public function testGivingUpEscalatesToTheFallbackChannel(): void {
    $this->makeAlert('permanent');

    Channel::create([
      'id' => 'fallback',
      'label' => 'Fallback',
      'status' => TRUE,
      'transport' => ['id' => 'failing', 'mode' => 'ok'],
    ])->save();
    $this->config('klaxon.settings')->set('fallback_channel', 'fallback')->save();

    $this->dispatcher()->fire('a');
    $this->processQueue();

    $delivered = $this->delivered();
    $this->assertCount(1, $delivered, 'Exactly one notice went out.');
    $this->assertSame('Klaxon could not deliver an alert', $delivered[0]);
  }

  /**
   * A channel switched off while the job waited swallows it quietly.
   */
  public function testDisabledChannelDropsTheJob(): void {
    $this->makeAlert('ok');
    $this->dispatcher()->fire('a');

    Channel::load('c')->set('status', FALSE)->save();
    $this->processQueue();

    $this->assertSame(0, $this->attempts(), 'The transport was never called.');
    $this->assertSame(0, $this->queue()->numberOfItems(), 'And it was not retried.');
  }

  /**
   * Creates a channel using the test transport, plus an alert pointing at it.
   */
  protected function makeAlert(string $mode): void {
    Channel::create([
      'id' => 'c',
      'label' => 'Test channel',
      'status' => TRUE,
      'transport' => ['id' => 'failing', 'mode' => $mode],
    ])->save();

    Alert::create([
      'id' => 'a',
      'label' => 'Something happened',
      'status' => TRUE,
      'type' => ['id' => 'code'],
      'channels' => ['c'],
      'notify_on' => 'every',
      'subject' => 'Something happened',
    ])->save();
  }

  /**
   * Works the queue the way cron does, one pass.
   */
  protected function processQueue(): void {
    $queue = $this->queue();
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance(Deliverer::QUEUE);

    while ($item = $queue->claimItem()) {
      try {
        $worker->processItem($item->data);
        $queue->deleteItem($item);
      }
      catch (SuspendQueueException) {
        // Core releases the claimed item and moves on to the next queue.
        $queue->releaseItem($item);
        break;
      }
    }
  }

  /**
   * The delivery queue.
   */
  protected function queue() {
    return $this->container->get('queue')->get(Deliverer::QUEUE);
  }

  /**
   * The dispatcher under test.
   */
  protected function dispatcher() {
    return $this->container->get('klaxon.dispatcher');
  }

  /**
   * How many times the test transport was asked to deliver.
   */
  protected function attempts(): int {
    return (int) $this->container->get('state')->get(Failing::ATTEMPTS, 0);
  }

  /**
   * The subjects the test transport actually delivered.
   */
  protected function delivered(): array {
    return (array) $this->container->get('state')->get(Failing::DELIVERED, []);
  }

}
