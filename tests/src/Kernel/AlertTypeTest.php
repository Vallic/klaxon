<?php

declare(strict_types=1);

namespace Drupal\Tests\klaxon\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\klaxon\Alert\Deliverer;
use Drupal\klaxon\AlertType\EntityEventAlertInterface;
use Drupal\klaxon\AlertType\ScheduledAlertInterface;
use Drupal\klaxon\Entity\Alert;
use Drupal\klaxon\Entity\Channel;
use Drupal\klaxon_test\Plugin\Klaxon\Transport\Failing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers what decides that an alert runs, and what decides that it fires.
 *
 * Which interfaces an alert type implements is the whole dispatch mechanism —
 * nobody configures "this one is on cron" — so each route is checked here
 * against a type that should take it and a type that should not.
 */
#[Group('klaxon')]
#[RunTestsInSeparateProcesses]
class AlertTypeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'entity_test', 'klaxon', 'klaxon_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
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
   * Every shipped type survives a save and reload, schema and all.
   */
  public function testEachTypeRoundTrips(): void {
    $cases = [
      'entity_query' => [
        'config' => ['entity_type' => 'entity_test', 'operator' => '>', 'value' => 0],
        'implements' => ScheduledAlertInterface::class,
      ],
      'entity_event' => [
        'config' => ['entity_type' => 'entity_test', 'operations' => ['insert']],
        'implements' => EntityEventAlertInterface::class,
      ],
      'code' => [
        'config' => [],
        'implements' => NULL,
      ],
    ];

    foreach ($cases as $id => $case) {
      $this->makeAlert($id, ['id' => $id] + $case['config']);
      $type = Alert::load($id)->getType();

      $this->assertSame($id, $type->getPluginId());
      $this->assertNotSame('', $type->summary(), 'The type describes itself.');

      if ($case['implements'] !== NULL) {
        $this->assertInstanceOf($case['implements'], $type);
      }
    }

    // The code-fired type takes neither dispatch route, which is what makes it
    // a blank a developer aims wherever they like.
    $code = Alert::load('code')->getType();
    $this->assertNotInstanceOf(ScheduledAlertInterface::class, $code);
    $this->assertNotInstanceOf(EntityEventAlertInterface::class, $code);
  }

  /**
   * Cron runs the scheduled type and leaves the other two alone.
   */
  public function testOnlyScheduledTypesRunOnCron(): void {
    EntityTest::create(['name' => 'one'])->save();
    EntityTest::create(['name' => 'two'])->save();

    $this->makeAlert('counted', [
      'id' => 'entity_query',
      'entity_type' => 'entity_test',
      'aggregate' => 'count',
      'operator' => '>=',
      'value' => 2,
      'interval' => 300,
    ]);
    $this->makeAlert('code_only', ['id' => 'code']);

    $this->assertSame(1, $this->dispatcher()->runDue(), 'Only the scheduled alert ran.');
    $this->assertSame(['counted'], $this->delivered());
  }

  /**
   * A save runs the event type and leaves the scheduled one alone.
   */
  public function testOnlyEventTypesRunOnSave(): void {
    $this->makeAlert('created', [
      'id' => 'entity_event',
      'entity_type' => 'entity_test',
      'operations' => ['insert'],
    ]);
    $this->makeAlert('counted', [
      'id' => 'entity_query',
      'entity_type' => 'entity_test',
      'operator' => '>',
      'value' => 0,
    ]);

    EntityTest::create(['name' => 'one'])->save();
    $this->runQueue();

    $this->assertSame(['created'], $this->delivered(), 'The query alert did not run on a save.');
  }

  /**
   * Nothing evaluates a code-fired alert until code says so.
   */
  public function testCodeFiredWaitsToBeAsked(): void {
    $this->makeAlert('backup', ['id' => 'code']);

    EntityTest::create(['name' => 'one'])->save();
    $this->dispatcher()->runDue();
    $this->runQueue();

    $this->assertSame([], $this->delivered(), 'Neither cron nor a save reached it.');

    $this->dispatcher()->fire('backup', ['rows' => ['srv-1' => ['label' => 'srv-1']]]);
    $this->runQueue();

    $this->assertSame(['backup'], $this->delivered());
  }

  /**
   * The dead man's switch: firing because nothing was found.
   */
  public function testEmptyOperatorFiresOnNothing(): void {
    $this->makeAlert('quiet', [
      'id' => 'entity_query',
      'entity_type' => 'entity_test',
      'operator' => 'empty',
    ]);

    $this->assertSame(1, $this->dispatcher()->runDue(), 'No content at all is the alarm.');
    $this->assertSame(['quiet'], $this->delivered());

    // Something exists now, so the switch goes quiet again.
    EntityTest::create(['name' => 'one'])->save();
    $this->runQueue();
    $this->container->get('state')->set(Failing::DELIVERED, []);

    $this->dispatcher()->fireNow('quiet');
    $this->assertSame([], $this->delivered());
  }

  /**
   * A threshold that is not crossed says nothing.
   */
  public function testThresholdBelowTheLineStaysQuiet(): void {
    EntityTest::create(['name' => 'one'])->save();

    $this->makeAlert('busy', [
      'id' => 'entity_query',
      'entity_type' => 'entity_test',
      'operator' => '>',
      'value' => 5,
    ]);

    $this->assertFalse($this->dispatcher()->fireNow('busy'));
    $this->assertSame([], $this->delivered());
  }

  /**
   * An alert whose type cannot read is logged, not delivered.
   */
  public function testUnreadableTypeDoesNotDeliver(): void {
    $this->makeAlert('broken', [
      'id' => 'entity_query',
      'entity_type' => 'no_such_entity_type',
      'operator' => '>',
      'value' => 0,
    ]);

    $this->assertFalse($this->dispatcher()->fireNow('broken'));
    $this->assertSame([], $this->delivered());
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
   * Works the delivery queue the way cron does.
   */
  protected function runQueue(): void {
    $queue = $this->container->get('queue')->get(Deliverer::QUEUE);
    $worker = $this->container->get('plugin.manager.queue_worker')
      ->createInstance(Deliverer::QUEUE);

    while (is_object($item = $queue->claimItem())) {
      $worker->processItem($item->data);
      $queue->deleteItem($item);
    }
  }

  /**
   * The dispatcher under test.
   */
  protected function dispatcher() {
    return $this->container->get('klaxon.dispatcher');
  }

  /**
   * The subjects the test transport actually delivered.
   */
  protected function delivered(): array {
    $this->runQueue();
    return (array) $this->container->get('state')->get(Failing::DELIVERED, []);
  }

}
