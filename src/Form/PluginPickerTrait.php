<?php

declare(strict_types=1);

namespace Drupal\klaxon\Form;

use Drupal\Component\Plugin\PluginManagerInterface;

/**
 * Builds the grouped select both of Klaxon's pickers use.
 *
 * Alert types and transports are picked the same way and grow the same way:
 * one submodule adds four of them and a flat list stops being readable. The
 * grouping is the only part worth sharing — what each form does with the
 * choice afterwards has nothing in common.
 */
trait PluginPickerTrait {

  /**
   * Every plugin a manager knows about, grouped under its category.
   *
   * @param \Drupal\Component\Plugin\PluginManagerInterface $manager
   *   The manager to list.
   *
   * @return array
   *   Select options, nested one level so each category becomes an optgroup.
   *   A plugin naming no category sits at the top level rather than in a
   *   group called "Other", which would read as a category of its own.
   */
  protected function pluginOptions(PluginManagerInterface $manager): array {
    $options = [];

    foreach ($manager->getDefinitions() as $id => $definition) {
      $category = (string) ($definition['category'] ?? '');
      $label = (string) ($definition['label'] ?? $id);

      if ($category === '') {
        $options[$id] = $label;
        continue;
      }

      $options[$category][$id] = $label;
    }

    foreach ($options as &$group) {
      if (is_array($group)) {
        natcasesort($group);
      }
    }
    unset($group);

    uksort($options, 'strnatcasecmp');

    return $options;
  }

}
