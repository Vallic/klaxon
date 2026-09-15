<?php

declare(strict_types=1);

namespace Drupal\Tests\klaxon\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\klaxon\Alert\Deliverer;
use Drupal\klaxon\Entity\Alert;
use Drupal\klaxon\Entity\Channel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers what cron does, and the setting that tells it to stop doing it.
 *
 * Cron is the fallback: a site that schedules nothing still runs its alerts.
 * A site whose scheduler runs klaxon:due and klaxon:deliver has already said
 * who owns that work, and can turn cron's copy of it off.
 */
#[Group('klaxon')]
#[RunTestsInSeparateProcesses]
class CronTest extends KernelTestBase {

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

    Channel::create([
      'id' => 'c',
      'label' => 'Test channel',
      'status' => TRUE,
      'transport' => ['id' => 'failing', 'mode' => 'ok'],
    ])->save();
  }

  /**
   * The default is on, so a site that schedules nothing still gets alerts.
   */
  public function testCronEvaluatesAlertsByDefault(): void {
    $this->assertTrue($this->config('klaxon.settings')->get('run_on_cron'));

    $this->makeScheduledAlert();
    $this->assertNull($this->lastRun(), 'Nothing has evaluated it yet.');

    $this->container->get('cron')->run();

    $this->assertNotNull($this->lastRun(), 'Cron evaluated it.');
  }

  /**
   * Switched off, cron leaves the alerts alone.
   */
  public function testCronEvaluatesNothingWhenSwitchedOff(): void {
    $this->container->get('config.factory')->getEditable('klaxon.settings')->set('run_on_cron', FALSE)->save();

    $this->makeScheduledAlert();
    $this->container->get('cron')->run();

    $this->assertNull($this->lastRun(), 'Cron left it for whatever else was scheduled.');
  }

  /**
   * Switched off, the delivery queue comes off cron as well.
   *
   * The queue worker asks cron for 30 seconds a run, which a setting cannot
   * reach on its own - it is declared on the plugin. Leaving cron draining a
   * queue that `drush klaxon:deliver` already drains is work nobody asked
   * for, so the definition is altered instead.
   */
  public function testTheDeliveryQueueComesOffCronToo(): void {
    $definition = $this->queueDefinition();
    $this->assertSame(['time' => 30], $definition['cron'] ?? NULL, 'On cron by default.');

    $this->container->get('config.factory')->getEditable('klaxon.settings')->set('run_on_cron', FALSE)->save();

    $this->assertArrayNotHasKey('cron', $this->queueDefinition(), 'Off cron when cron is off.');
  }

  /**
   * Housekeeping runs either way: it has no command to hand over to.
   */
  public function testPruningHappensWhateverTheSettingSays(): void {
    foreach ([TRUE, FALSE] as $on) {
      $this->container->get('config.factory')->getEditable('klaxon.settings')->set('run_on_cron', $on)->save();

      $stale = $this->container->get('datetime.time')->getRequestTime() - (200 * 86400);
      $this->container->get('database')->insert('klaxon_ledger')
        ->fields(['alert_id' => 'a', 'row_key' => 'old-' . (int) $on, 'fired' => $stale])
        ->execute();

      $this->container->get('cron')->run();

      $left = $this->container->get('database')->select('klaxon_ledger', 'l')
        ->condition('row_key', 'old-' . (int) $on)
        ->countQuery()->execute()->fetchField();

      $this->assertSame('0', (string) $left, sprintf('Pruned with run_on_cron=%s.', var_export($on, TRUE)));
    }
  }

  /**
   * An alert on a schedule, never yet evaluated.
   */
  protected function makeScheduledAlert(): void {
    Alert::create([
      'id' => 'scheduled',
      'label' => 'Scheduled probe',
      'status' => TRUE,
      'type' => [
        'id' => 'entity_query',
        'entity_type' => 'user',
        'aggregate' => 'count',
        'operator' => '>=',
        'value' => 0,
        'interval' => 60,
      ],
      'channels' => ['c'],
      'notify_on' => 'change',
    ])->save();
  }

  /**
   * When the scheduled alert was last evaluated, or NULL.
   */
  protected function lastRun(): ?int {
    return $this->container->get('klaxon.state')->get('scheduled')->lastRun;
  }

  /**
   * The delivery queue worker's definition, freshly built.
   */
  protected function queueDefinition(): array {
    $manager = $this->container->get('plugin.manager.queue_worker');
    $manager->clearCachedDefinitions();

    return (array) $manager->getDefinition(Deliverer::QUEUE);
  }

}
