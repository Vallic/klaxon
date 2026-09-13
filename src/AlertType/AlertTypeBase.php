<?php

declare(strict_types=1);

namespace Drupal\klaxon\AlertType;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\klaxon\ConfigurablePluginTrait;
use Drupal\klaxon\Reading;

/**
 * Base class for Klaxon alert types.
 */
abstract class AlertTypeBase extends PluginBase implements AlertTypeInterface {

  use ConfigurablePluginTrait;

  /**
   * {@inheritdoc}
   */
  public function fires(Reading $reading): bool {
    // An alert type that has to be provoked into running — by a save, or by
    // code — has already been told something happened. Second-guessing that
    // with a test is what a scheduled type is for.
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): string {
    return (string) ($this->pluginDefinition['label'] ?? $this->getPluginId());
  }

  /**
   * Turns whatever the caller supplied into a reading.
   *
   * Shared by the types that measure nothing themselves. Rows given explicitly
   * win, then a scalar value, then a single entity in context; anything else
   * is an empty reading carrying only the facts.
   */
  protected function readFromContext(array $context): Reading {
    $facts = (array) ($context['facts'] ?? []);

    // Keep any entity in context so message tokens can reach it.
    foreach ($context as $key => $value) {
      if ($value instanceof EntityInterface) {
        $facts[$key] = $value;
      }
    }

    if (isset($context['rows']) && is_array($context['rows'])) {
      return Reading::fromRows($context['rows'], $facts);
    }

    if (isset($context['value']) && is_numeric($context['value'])) {
      return Reading::scalar($context['value'] + 0, $facts);
    }

    if (($context['entity'] ?? NULL) instanceof EntityInterface) {
      return Reading::fromRows([
        (string) $context['entity']->id() => $this->entityRow($context['entity']),
      ], $facts);
    }

    return Reading::nothing($facts);
  }

  /**
   * One entity as a message row: what it is called, and where to find it.
   */
  protected function entityRow(EntityInterface $entity): array {
    $row = ['label' => (string) $entity->label()];

    try {
      $row['url'] = $entity->toUrl('canonical', ['absolute' => TRUE])->toString();
    }
    catch (\Throwable) {
      // Plenty of entity types have no canonical link. Not worth reporting.
    }

    return $row;
  }

}
