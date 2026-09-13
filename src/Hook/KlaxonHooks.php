<?php

declare(strict_types=1);

namespace Drupal\klaxon\Hook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
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
  ) {}

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->dispatcher->runDue();
    $this->state->pruneLedger($this->time->getRequestTime() - self::LEDGER_RETENTION);
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
