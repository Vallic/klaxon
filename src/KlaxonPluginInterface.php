<?php

declare(strict_types=1);

namespace Drupal\klaxon;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\DependentPluginInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Plugin\PluginFormInterface;

/**
 * What every Klaxon plugin can do, whatever question it answers.
 *
 * Both plugin types — an alert type and a transport — are configured the same
 * way, describe themselves the same way and build their settings form the same
 * way. Naming that shared contract lets the alert form and the channel form
 * embed a plugin's settings without either knowing which kind it holds.
 */
interface KlaxonPluginInterface extends ConfigurableInterface, PluginInspectionInterface, PluginFormInterface, DependentPluginInterface {

  /**
   * One line describing what this plugin is configured to do.
   */
  public function summary(): string;

}
