<?php

declare(strict_types=1);

namespace Drupal\klaxon_system\Hook;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for Klaxon System.
 */
class KlaxonSystemHooks {

  public function __construct(
    protected readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Implements hook_klaxon_alert_type_info_alter().
   *
   * The log alert reads the watchdog table directly, which only exists while
   * Database Logging is installed. Rather than make the whole submodule
   * depend on dblog — the other two alerts have nothing to do with it — the
   * one alert takes itself off the list.
   */
  #[Hook('klaxon_alert_type_info_alter')]
  public function alertTypeInfoAlter(array &$definitions): void {
    if (!$this->moduleHandler->moduleExists('dblog')) {
      unset($definitions['system_errors']);
    }
  }

}
