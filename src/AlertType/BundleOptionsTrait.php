<?php

declare(strict_types=1);

namespace Drupal\klaxon\AlertType;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;

/**
 * Offers the real bundles of the chosen entity type, rather than a text field.
 *
 * Typing a machine name from memory is how an alert ends up silently watching
 * nothing: a bundle that does not exist is not an error, it is a condition
 * that never matches.
 *
 * The list has to follow the entity type select, and that is the awkward part.
 * A plugin's settings form is built before Drupal has walked the form and
 * assigned #parents, so at that moment there is no way to ask what the entity
 * type select currently holds — which is why the bundle list is filled in by
 * a #process callback instead, running late enough to know both where it sits
 * and what its sibling was set to.
 */
trait BundleOptionsTrait {

  /**
   * The container that fills itself with the bundles of the chosen type.
   *
   * @param string $key
   *   The form key the chosen bundles are stored under, inside the container.
   * @param array $settings
   *   Everything the process callback needs to build the widget: 'widget'
   *   ('checkboxes' or 'select'), 'title', 'description', 'default', and the
   *   'fallback' entity type to use before anything has been submitted.
   *
   * @return array
   *   A container element. It stays in the form even when the entity type has
   *   no bundles, because AJAX needs something on the page to replace.
   */
  protected function bundlesElement(string $key, array $settings): array {
    return [
      '#type' => 'container',
      '#attributes' => ['id' => $this->bundlesWrapperId()],
      '#process' => [[static::class, 'processBundles']],
      '#klaxon_bundles' => $settings + ['key' => $key, 'widget' => 'checkboxes'],
    ];
  }

  /**
   * The DOM id of the container holding the bundle list.
   */
  protected function bundlesWrapperId(): string {
    return 'klaxon-bundles-' . str_replace('_', '-', $this->getPluginId());
  }

  /**
   * The AJAX settings that make an entity type select reload the bundles.
   */
  protected function bundlesAjax(): array {
    return [
      'callback' => [static::class, 'updateBundles'],
      'wrapper' => $this->bundlesWrapperId(),
    ];
  }

  /**
   * Fills the container with whatever the chosen entity type has.
   *
   * Runs while Drupal walks the form, so #parents are set and the entity type
   * select — a sibling, declared before this — already has its value.
   */
  public static function processBundles(array &$element, FormStateInterface $form_state, array &$complete_form): array {
    $settings = $element['#klaxon_bundles'];

    $parents = $element['#parents'];
    array_pop($parents);
    $parents[] = 'entity_type';

    // Nothing submitted yet on a first build, so fall back to what the alert
    // was saved with.
    $entity_type = (string) ($form_state->getValue($parents) ?? $settings['fallback'] ?? '');
    $options = static::bundleOptionsFor($entity_type);

    if ($options === []) {
      return $element;
    }

    $element[$settings['key']] = [
      '#type' => $settings['widget'],
      '#title' => $settings['title'],
      '#description' => $settings['description'] ?? NULL,
      '#default_value' => $settings['default'] ?? ($settings['widget'] === 'checkboxes' ? [] : ''),
      '#options' => $settings['widget'] === 'select'
        ? ['' => t('- Any -')] + $options
        : $options,
    ];

    return $element;
  }

  /**
   * AJAX callback returning the bundle list for the entity type just picked.
   */
  public static function updateBundles(array $form, FormStateInterface $form_state): array {
    $parents = $form_state->getTriggeringElement()['#array_parents'];

    // The container is a sibling of the select that triggered this, wherever
    // in the form the subform happens to be embedded.
    array_pop($parents);
    $parents[] = 'bundles_wrapper';

    return NestedArray::getValue($form, $parents);
  }

  /**
   * The bundles of an entity type, or none when it has no real ones.
   *
   * Static, because the process callback that needs it cannot carry a plugin
   * instance through a cached form array.
   *
   * @param string $entity_type_id
   *   The entity type to list bundles for.
   *
   * @return array
   *   Bundle labels keyed by machine name. Empty when the entity type has no
   *   bundle key, or when its only bundle is itself — which is how core
   *   describes an entity type that is not bundled at all.
   */
  protected static function bundleOptionsFor(string $entity_type_id): array {
    $entity_type_manager = \Drupal::entityTypeManager();

    if ($entity_type_id === '' || !$entity_type_manager->hasDefinition($entity_type_id)) {
      return [];
    }

    if (!$entity_type_manager->getDefinition($entity_type_id)->hasKey('bundle')) {
      return [];
    }

    $options = [];
    foreach (\Drupal::service('entity_type.bundle.info')->getBundleInfo($entity_type_id) as $id => $info) {
      $options[(string) $id] = (string) ($info['label'] ?? $id);
    }

    if (array_keys($options) === [$entity_type_id]) {
      return [];
    }

    natcasesort($options);

    return $options;
  }

}
