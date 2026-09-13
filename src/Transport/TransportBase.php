<?php

declare(strict_types=1);

namespace Drupal\klaxon\Transport;

use Drupal\Core\Plugin\PluginBase;
use Drupal\klaxon\ConfigurablePluginTrait;

/**
 * Base class for Klaxon transports.
 */
abstract class TransportBase extends PluginBase implements TransportInterface {

  use ConfigurablePluginTrait;

  /**
   * {@inheritdoc}
   */
  public function summary(): string {
    return (string) ($this->pluginDefinition['label'] ?? $this->getPluginId());
  }

}
