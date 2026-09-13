<?php

declare(strict_types=1);

namespace Drupal\klaxon\Plugin\Klaxon\AlertType;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\klaxon\AlertType\AlertTypeBase;
use Drupal\klaxon\AlertType\BundleOptionsTrait;
use Drupal\klaxon\AlertType\EntityEventAlertInterface;
use Drupal\klaxon\Attribute\AlertType;
use Drupal\klaxon\Reading;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Fires when something is created, changed or deleted.
 *
 * Deliberately generic. "An order was cancelled" is this watching a state
 * field for a new value, which is the same shape as "a backup record failed"
 * or "a lot went unsold", so none of those needs its own plugin.
 *
 * The entity that changed becomes the message: one row, with its label and a
 * link. Anything needing more than that wants its own alert type.
 */
#[AlertType(
  id: 'entity_event',
  label: new TranslatableMarkup('When content changes'),
  description: new TranslatableMarkup('Fire the moment an entity is created, updated or deleted, optionally only when one of its fields takes a particular value.'),
  category: new TranslatableMarkup('General'),
)]
class EntityEvent extends AlertTypeBase implements EntityEventAlertInterface, ContainerFactoryPluginInterface {

  use BundleOptionsTrait;
  use StringTranslationTrait;

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntityTypeBundleInfoInterface $bundleInfo,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->setConfiguration($configuration);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'entity_type' => '',
      'bundles' => [],
      'operations' => ['update'],
      // Optional field watch. Leave the field empty to fire on any change.
      'field' => '',
      'from_value' => '',
      'to_value' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $options = [];
    foreach ($this->entityTypeManager->getDefinitions() as $id => $definition) {
      if ($definition->entityClassImplements(ContentEntityInterface::class)) {
        $options[$id] = sprintf('%s (%s)', (string) $definition->getLabel(), $id);
      }
    }
    natcasesort($options);

    $form['entity_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Content to watch'),
      '#options' => $options,
      '#default_value' => $this->configuration['entity_type'] ?? '',
      '#required' => TRUE,
      '#ajax' => $this->bundlesAjax(),
    ];

    $form['bundles_wrapper'] = $this->bundlesElement('bundles', [
      'widget' => 'checkboxes',
      'title' => $this->t('Limited to types'),
      'description' => $this->t('Leave everything unticked for every type.'),
      'default' => (array) ($this->configuration['bundles'] ?? []),
      'fallback' => (string) ($this->configuration['entity_type'] ?? ''),
    ]);

    $form['operations'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('React when it is'),
      '#options' => [
        'insert' => $this->t('Created'),
        'update' => $this->t('Updated'),
        'delete' => $this->t('Deleted'),
      ],
      '#default_value' => (array) ($this->configuration['operations'] ?? ['update']),
      '#required' => TRUE,
    ];

    $form['watch'] = [
      '#type' => 'details',
      '#title' => $this->t('Only when a field changes'),
      '#open' => ($this->configuration['field'] ?? '') !== '',
      '#description' => $this->t('This is how you say "an order was cancelled": watch the state field for its new value. On update the field must actually move, so the alert does not fire again on every later save.'),
    ];

    $form['watch']['field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Field'),
      '#default_value' => $this->configuration['field'] ?? '',
      '#description' => $this->t('For example %state.', ['%state' => 'state']),
    ];

    $form['watch']['from_value'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Changing from'),
      '#default_value' => $this->configuration['from_value'] ?? '',
      '#description' => $this->t('Leave empty to accept any previous value.'),
    ];

    $form['watch']['to_value'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Changing to'),
      '#default_value' => $this->configuration['to_value'] ?? '',
      '#description' => $this->t('Leave empty to accept any new value.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();

    // The field watch lives in a details element, so flatten it back out.
    foreach ((array) ($values['watch'] ?? []) as $key => $value) {
      $values[$key] = $value;
    }
    unset($values['watch']);

    $values['operations'] = array_values(array_filter((array) ($values['operations'] ?? [])));

    // The bundle list lives in its own container so AJAX has something stable
    // to replace, which is not a shape worth keeping in configuration.
    $values['bundles'] = array_values(array_filter(
      (array) ($values['bundles_wrapper']['bundles'] ?? []),
    ));
    unset($values['bundles_wrapper']);

    $this->setConfiguration($values);
  }

  /**
   * {@inheritdoc}
   */
  public function applies(string $entity_type_id): bool {
    return $entity_type_id === (string) ($this->configuration['entity_type'] ?? '');
  }

  /**
   * {@inheritdoc}
   */
  public function matches(EntityInterface $entity, string $operation): bool {
    $operations = (array) ($this->configuration['operations'] ?? []);
    if (!in_array($operation, $operations, TRUE)) {
      return FALSE;
    }

    $bundles = array_filter((array) ($this->configuration['bundles'] ?? []));
    if ($bundles !== [] && !in_array($entity->bundle(), $bundles, TRUE)) {
      return FALSE;
    }

    $field = (string) ($this->configuration['field'] ?? '');
    if ($field === '') {
      return TRUE;
    }

    if (!$entity instanceof FieldableEntityInterface || !$entity->hasField($field)) {
      return FALSE;
    }

    $new = $this->readField($entity, $field);
    $to = (string) ($this->configuration['to_value'] ?? '');

    if ($to !== '' && $new !== $to) {
      return FALSE;
    }

    // On update, insist the field actually moved. Without this the alert fires
    // on every subsequent save of an already-cancelled order.
    if ($operation === 'update') {
      $original = $entity->getOriginal();

      if ($original instanceof FieldableEntityInterface && $original->hasField($field)) {
        $old = $this->readField($original, $field);

        if ($old === $new) {
          return FALSE;
        }

        $from = (string) ($this->configuration['from_value'] ?? '');
        if ($from !== '' && $old !== $from) {
          return FALSE;
        }
      }
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function read(array $context = []): Reading {
    return $this->readFromContext($context);
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): string {
    $field = (string) ($this->configuration['field'] ?? '');
    $to = (string) ($this->configuration['to_value'] ?? '');

    if ($field !== '' && $to !== '') {
      return (string) new TranslatableMarkup('When @type.@field becomes @value', [
        '@type' => $this->configuration['entity_type'] ?: '?',
        '@field' => $field,
        '@value' => $to,
      ]);
    }

    return (string) new TranslatableMarkup('When @type is @ops', [
      '@type' => $this->configuration['entity_type'] ?: '?',
      '@ops' => implode(' or ', (array) ($this->configuration['operations'] ?? [])),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): array {
    $entity_type_id = (string) ($this->configuration['entity_type'] ?? '');

    if ($entity_type_id === '' || !$this->entityTypeManager->hasDefinition($entity_type_id)) {
      return [];
    }

    return ['module' => [$this->entityTypeManager->getDefinition($entity_type_id)->getProvider()]];
  }

  /**
   * The first value of a field, flattened for comparison.
   */
  protected function readField(FieldableEntityInterface $entity, string $field): string {
    $item = $entity->get($field)->first();

    if ($item === NULL) {
      return '';
    }

    $value = $item->getValue();

    return (string) reset($value);
  }

}
