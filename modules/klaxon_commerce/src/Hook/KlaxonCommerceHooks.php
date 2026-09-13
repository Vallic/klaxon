<?php

declare(strict_types=1);

namespace Drupal\klaxon_commerce\Hook;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for Klaxon Commerce.
 */
class KlaxonCommerceHooks {

  public function __construct(
    protected readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * Implements hook_klaxon_alert_type_info_alter().
   *
   * The cart flag is Commerce Cart's, not Commerce Order's. On a site running
   * orders without carts there is nothing for the abandoned-cart alert to
   * count, so it is taken off the list rather than left there to be configured
   * into a query that cannot run.
   */
  #[Hook('klaxon_alert_type_info_alter')]
  public function alertTypeInfoAlter(array &$definitions): void {
    $fields = $this->entityFieldManager->getFieldStorageDefinitions('commerce_order');

    if (!isset($fields['cart'])) {
      unset($definitions['commerce_abandoned_carts']);
    }
  }

}
