<?php

declare(strict_types=1);

namespace Drupal\klaxon\Hook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\klaxon\Alert\Deliverer;
use Drupal\klaxon\Alert\Dispatcher;
use Drupal\klaxon\Alert\StateStore;

/**
 * Hook implementations for Klaxon.
 */
class KlaxonHooks {

  /**
   * Ledger rows older than this are pruned on cron.
   */
  protected const LEDGER_RETENTION = 90 * 86400;

  /**
   * Klaxon's own config entities never trigger alerts, to avoid feedback.
   */
  protected const OWN_ENTITY_TYPES = ['klaxon_alert', 'klaxon_channel'];

  public function __construct(
    protected readonly Dispatcher $dispatcher,
    protected readonly StateStore $state,
    protected readonly TimeInterface $time,
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function cron(): void {
    // Cron is the fallback, not the only way in. A site whose scheduler runs
    // `drush klaxon:due` on its own turns this off, so alerts are evaluated
    // once on that schedule rather than twice on two.
    //
    // Nothing breaks when both run - runDue() honours each alert's own
    // interval, and an alert not yet due is a no-op - but a site that has
    // taken the trouble to schedule it should be able to say so.
    if ($this->configFactory->get('klaxon.settings')->get('run_on_cron') ?? TRUE) {
      $this->dispatcher->runDue();
    }

    // Pruning is housekeeping and belongs on cron either way: it is not the
    // work a scheduler was asked to take over, and it has no command of its
    // own to take it over with.
    $this->state->pruneLedger($this->time->getRequestTime() - self::LEDGER_RETENTION);
  }

  /**
   * Implements hook_queue_info_alter().
   *
   * Takes the delivery queue off cron when cron has been switched off.
   *
   * The queue worker asks cron for 30 seconds a run. That is the right
   * default, but a site running `drush klaxon:deliver` on a schedule has
   * already said who drains the queue, and leaving cron at it as well means
   * two things doing one job. Nothing is delivered twice either way - the
   * queue leases each item - but cron spending 30 seconds on a queue that is
   * already empty is work nobody asked for.
   */
  #[Hook('queue_info_alter')]
  public function queueInfoAlter(array &$queues): void {
    if ($this->configFactory->get('klaxon.settings')->get('run_on_cron') ?? TRUE) {
      return;
    }

    unset($queues[Deliverer::QUEUE]['cron']);
  }

  /**
   * Implements hook_entity_insert().
   */
  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity): void {
    $this->react($entity, 'insert');
  }

  /**
   * Implements hook_entity_update().
   */
  #[Hook('entity_update')]
  public function entityUpdate(EntityInterface $entity): void {
    $this->react($entity, 'update');
  }

  /**
   * Implements hook_entity_delete().
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    $this->react($entity, 'delete');

    if ($entity->getEntityTypeId() === 'klaxon_alert') {
      $this->state->forget((string) $entity->id());
    }
  }

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'klaxon_firing' => ['variables' => ['rows' => []]],
      'klaxon_channel_card' => [
        'variables' => [
          'label' => '',
          'transport' => '',
          'enabled' => TRUE,
          'edit_url' => '',
          'alerts' => [],
        ],
      ],
    ];
  }

  /**
   * Implements hook_mail().
   */
  #[Hook('mail')]
  public function mail(string $key, array &$message, array $params): void {
    if ($key !== 'alert') {
      return;
    }

    $message['subject'] = $params['subject'] ?? '';
    $message['body'][] = $params['body'] ?? '';
  }

  /**
   * Runs the alerts watching this entity change.
   */
  protected function react(EntityInterface $entity, string $operation): void {
    if (in_array($entity->getEntityTypeId(), self::OWN_ENTITY_TYPES, TRUE)) {
      return;
    }

    $this->dispatcher->runForEntity($entity, $operation);
  }

}
