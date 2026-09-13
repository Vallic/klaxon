<?php

declare(strict_types=1);

namespace Drupal\klaxon\AlertType;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\klaxon\Attribute\AlertType as AlertTypeAttribute;

/**
 * Plugin manager for Klaxon alert types.
 */
class AlertTypeManager extends DefaultPluginManager {

  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct(
      'Plugin/Klaxon/AlertType',
      $namespaces,
      $module_handler,
      AlertTypeInterface::class,
      AlertTypeAttribute::class,
    );
    $this->alterInfo('klaxon_alert_type_info');
    $this->setCacheBackend($cache_backend, 'klaxon_alert_type_plugins');
  }

}
