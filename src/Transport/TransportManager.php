<?php

declare(strict_types=1);

namespace Drupal\klaxon\Transport;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\klaxon\Attribute\Transport as TransportAttribute;

/**
 * Plugin manager for Klaxon transports.
 */
class TransportManager extends DefaultPluginManager {

  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct(
      'Plugin/Klaxon/Transport',
      $namespaces,
      $module_handler,
      TransportInterface::class,
      TransportAttribute::class,
    );
    $this->alterInfo('klaxon_transport_info');
    $this->setCacheBackend($cache_backend, 'klaxon_transport_plugins');
  }

}
