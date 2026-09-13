<?php

declare(strict_types=1);

namespace Drupal\klaxon\AlertType;

use Drupal\Core\Entity\EntityInterface;

/**
 * An alert type that reacts to content changing.
 */
interface EntityEventAlertInterface extends AlertTypeInterface {

  /**
   * TRUE when this type cares about the given entity type at all.
   *
   * Checked before anything is loaded into context, so the hook layer can skip
   * alerts cheaply on every save on the site.
   */
  public function applies(string $entity_type_id): bool;

  /**
   * TRUE when this specific change should evaluate the alert.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity that changed.
   * @param string $operation
   *   One of insert, update or delete.
   */
  public function matches(EntityInterface $entity, string $operation): bool;

}
