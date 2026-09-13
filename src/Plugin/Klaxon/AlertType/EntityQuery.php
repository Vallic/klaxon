<?php

declare(strict_types=1);

namespace Drupal\klaxon\Plugin\Klaxon\AlertType;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\klaxon\AlertType\BundleOptionsTrait;
use Drupal\klaxon\AlertType\ReadException;
use Drupal\klaxon\AlertType\ScheduledAlertBase;
use Drupal\klaxon\Attribute\AlertType;
use Drupal\klaxon\Reading;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Counts, sums or lists content on a schedule, and fires on the number.
 *
 * The workhorse, and the base class every prefilled alert type extends. It
 * covers most of what anyone actually asks for without needing Views: how many
 * of a thing there are, what they add up to, or which ones they are, inside a
 * time window, compared against a number.
 *
 * The date window accepts anything strtotime understands and is relative to
 * now, so it reaches forwards as readily as back: "-24 hours" to "now" is
 * yesterday's orders, "now" to "+1 hour" is what is about to end.
 *
 * Subclasses prefill defaultConfiguration() and drop whichever form sections
 * they have already decided. A "daily sales" alert is this class with the
 * entity type, the aggregate and the window fixed, leaving only the schedule
 * and the threshold on the form.
 */
#[AlertType(
  id: 'entity_query',
  label: new TranslatableMarkup('When a count or total crosses a line'),
  description: new TranslatableMarkup('Check on a schedule how many things there are, what they add up to, or which ones they are — and fire when that number crosses a threshold, or when nothing is found at all.'),
  category: new TranslatableMarkup('General'),
)]
class EntityQuery extends ScheduledAlertBase implements ContainerFactoryPluginInterface {

  use BundleOptionsTrait;

  public const COUNT = 'count';
  public const ROWS = 'rows';
  public const SUM = 'sum';

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly TimeInterface $time,
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
      $container->get('datetime.time'),
      $container->get('entity_type.bundle.info'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'entity_type' => '',
      'bundle' => '',
      // Each condition is ['field' => …, 'operator' => '=', 'value' => …].
      'conditions' => [],
      'date_field' => '',
      // 'timestamp' for created/changed style fields, 'iso' for datetime ones.
      'date_storage' => 'timestamp',
      'window_from' => '',
      'window_to' => '',
      'aggregate' => self::COUNT,
      'sum_field' => '',
      'limit' => 200,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['measure'] = $this->measureForm();
    $form['measure']['#weight'] = -5;

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function normalizeValues(array $values): array {
    // The bundle select lives in its own container so AJAX has something
    // stable to replace, which is not a shape worth keeping in configuration.
    if (array_key_exists('bundles_wrapper', $values)) {
      $values['bundle'] = (string) ($values['bundles_wrapper']['bundle'] ?? '');
      unset($values['bundles_wrapper']);
    }

    return $values;
  }

  /**
   * {@inheritdoc}
   */
  protected function formSections(): array {
    return ['schedule', 'measure', 'fire'];
  }

  /**
   * What to look at.
   */
  protected function measureForm(): array {
    $form = [
      '#type' => 'details',
      '#title' => $this->t('What to measure'),
      '#open' => TRUE,
    ];

    $form['entity_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Content to measure'),
      '#options' => $this->entityTypeOptions(),
      '#default_value' => $this->configuration['entity_type'] ?? '',
      '#required' => TRUE,
      '#ajax' => $this->bundlesAjax(),
    ];

    $form['bundles_wrapper'] = $this->bundlesElement('bundle', [
      'widget' => 'select',
      'title' => $this->t('Limited to type'),
      'default' => (string) ($this->configuration['bundle'] ?? ''),
      'fallback' => (string) ($this->configuration['entity_type'] ?? ''),
    ]);

    $form['aggregate'] = [
      '#type' => 'select',
      '#title' => $this->t('Measure'),
      '#options' => [
        self::COUNT => $this->t('How many there are'),
        self::ROWS => $this->t('Which ones they are'),
        self::SUM => $this->t('The total of a field'),
      ],
      '#default_value' => $this->configuration['aggregate'] ?? self::COUNT,
    ];

    $form['sum_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Field to add up'),
      '#default_value' => $this->configuration['sum_field'] ?? '',
      '#description' => $this->t('For example %example.', ['%example' => 'total_price.number']),
      '#states' => [
        'visible' => [':input[name$="[aggregate]"]' => ['value' => self::SUM]],
      ],
    ];

    $form['limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Most rows to list'),
      '#min' => 1,
      '#default_value' => $this->configuration['limit'] ?? 200,
      '#states' => [
        'visible' => [':input[name$="[aggregate]"]' => ['value' => self::ROWS]],
      ],
    ];

    $form['date_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Date field to window on'),
      '#default_value' => $this->configuration['date_field'] ?? '',
      '#description' => $this->t('For example %created or %placed. Leave empty to measure everything, whenever it happened.', [
        '%created' => 'created',
        '%placed' => 'placed',
      ]),
    ];

    $form['date_storage'] = [
      '#type' => 'select',
      '#title' => $this->t('Stored as'),
      '#options' => [
        'timestamp' => $this->t('Timestamp (created, changed, placed)'),
        'iso' => $this->t('Date string (datetime fields)'),
      ],
      '#default_value' => $this->configuration['date_storage'] ?? 'timestamp',
      '#states' => [
        'invisible' => [':input[name$="[date_field]"]' => ['value' => '']],
      ],
    ];

    $form['window_from'] = [
      '#type' => 'textfield',
      '#title' => $this->t('From'),
      '#default_value' => $this->configuration['window_from'] ?? '',
      '#placeholder' => '-24 hours',
      '#description' => $this->t('Relative to now. Reaches forwards too, so %now to %soon is what is about to happen.', [
        '%now' => 'now',
        '%soon' => '+1 hour',
      ]),
      '#states' => [
        'invisible' => [':input[name$="[date_field]"]' => ['value' => '']],
      ],
    ];

    $form['window_to'] = [
      '#type' => 'textfield',
      '#title' => $this->t('To'),
      '#default_value' => $this->configuration['window_to'] ?? '',
      '#placeholder' => 'now',
      '#states' => [
        'invisible' => [':input[name$="[date_field]"]' => ['value' => '']],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function read(array $context = []): Reading {
    $entity_type_id = (string) ($this->configuration['entity_type'] ?? '');

    if ($entity_type_id === '' || !$this->entityTypeManager->hasDefinition($entity_type_id)) {
      throw new ReadException(sprintf('Unknown entity type "%s".', $entity_type_id));
    }

    $cacheability = new CacheableMetadata();
    $cacheability->addCacheTags($this->entityTypeManager->getDefinition($entity_type_id)->getListCacheTags());

    $aggregate = (string) ($this->configuration['aggregate'] ?? self::COUNT);

    if ($aggregate === self::SUM) {
      return Reading::scalar($this->sum($entity_type_id), $this->contextFacts(), $cacheability);
    }

    if ($aggregate === self::ROWS) {
      return Reading::fromRows($this->rows($entity_type_id), $this->contextFacts(), $cacheability);
    }

    $count = (int) $this->query($entity_type_id)->count()->execute();

    return Reading::scalar($count, $this->contextFacts(), $cacheability);
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): string {
    return (string) new TranslatableMarkup('@aggregate of @type@window, @test', [
      '@aggregate' => ucfirst((string) ($this->configuration['aggregate'] ?? self::COUNT)),
      '@type' => $this->configuration['entity_type'] ?: '?',
      '@window' => ($window = $this->windowSummary()) === '' ? '' : ' ' . $window,
      '@test' => $this->thresholdSummary(),
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
   * Content entity types that can sensibly be measured, keyed by ID.
   */
  protected function entityTypeOptions(): array {
    $options = [];

    foreach ($this->entityTypeManager->getDefinitions() as $id => $definition) {
      if ($definition->entityClassImplements(ContentEntityInterface::class)) {
        $options[$id] = sprintf('%s (%s)', (string) $definition->getLabel(), $id);
      }
    }

    natcasesort($options);

    return $options;
  }

  /**
   * Builds the entity query shared by every aggregate mode.
   *
   * Subclasses with a condition of their own to add — an order state, a
   * payment status — override this, call the parent and add to it.
   */
  protected function query(string $entity_type_id, bool $aggregate = FALSE) {
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    $definition = $this->entityTypeManager->getDefinition($entity_type_id);

    $query = $aggregate ? $storage->getAggregateQuery() : $storage->getQuery();
    // Alerts report on the whole site, not on what the cron user may view.
    $query->accessCheck(FALSE);

    $bundle = (string) ($this->configuration['bundle'] ?? '');
    $bundle_key = $definition->getKey('bundle');

    if ($bundle !== '' && $bundle_key) {
      $query->condition($bundle_key, $bundle);
    }

    foreach ((array) ($this->configuration['conditions'] ?? []) as $condition) {
      if (empty($condition['field'])) {
        continue;
      }

      $query->condition(
        (string) $condition['field'],
        $condition['value'] ?? NULL,
        (string) ($condition['operator'] ?? '='),
      );
    }

    $this->applyWindow($query);

    return $query;
  }

  /**
   * Narrows the query to the configured time window, if there is one.
   */
  protected function applyWindow($query): void {
    $date_field = (string) ($this->configuration['date_field'] ?? '');

    if ($date_field === '') {
      return;
    }

    $now = $this->time->getRequestTime();
    $from = (string) ($this->configuration['window_from'] ?? '');
    $to = (string) ($this->configuration['window_to'] ?? '');

    if ($from !== '' && ($stamp = strtotime($from, $now)) !== FALSE) {
      $query->condition($date_field, $this->formatBound($stamp), '>=');
    }

    if ($to !== '' && ($stamp = strtotime($to, $now)) !== FALSE) {
      $query->condition($date_field, $this->formatBound($stamp), '<=');
    }
  }

  /**
   * Datetime fields compare as ISO strings, everything else as timestamps.
   */
  protected function formatBound(int $timestamp): string|int {
    if ((string) ($this->configuration['date_storage'] ?? 'timestamp') === 'iso') {
      return gmdate('Y-m-d\TH:i:s', $timestamp);
    }

    return $timestamp;
  }

  /**
   * The matching entities as rows keyed by entity ID.
   */
  protected function rows(string $entity_type_id): array {
    $limit = max(1, (int) ($this->configuration['limit'] ?? 200));
    $ids = $this->query($entity_type_id)->range(0, $limit)->execute();

    if ($ids === []) {
      return [];
    }

    $rows = [];
    foreach ($this->entityTypeManager->getStorage($entity_type_id)->loadMultiple($ids) as $id => $entity) {
      assert($entity instanceof EntityInterface);
      $rows[(string) $id] = $this->entityRow($entity);
    }

    return $rows;
  }

  /**
   * The database-side sum of the configured field.
   */
  protected function sum(string $entity_type_id): float {
    $field = (string) ($this->configuration['sum_field'] ?? '');

    if ($field === '') {
      throw new ReadException('A sum needs a field to add up.');
    }

    $result = $this->query($entity_type_id, TRUE)
      ->aggregate($field, 'SUM')
      ->execute();

    $first = is_array($result) ? reset($result) : NULL;

    if (!is_array($first)) {
      return 0.0;
    }

    // The result key is derived from the field and function, and the exact
    // shape differs per field type, so take the value rather than guess it.
    return (float) reset($first);
  }

  /**
   * The configured time window in words, or an empty string.
   */
  protected function windowSummary(): string {
    $from = (string) ($this->configuration['window_from'] ?? '');
    $to = (string) ($this->configuration['window_to'] ?? '');

    if ($from === '' && $to === '') {
      return '';
    }

    return (string) new TranslatableMarkup('between @from and @to', [
      '@from' => $from !== '' ? $from : 'any time',
      '@to' => $to !== '' ? $to : 'any time',
    ]);
  }

  /**
   * Facts describing the measurement itself.
   */
  protected function contextFacts(): array {
    $facts = [];

    if (($window = $this->windowSummary()) !== '') {
      $facts['Window'] = $window;
    }

    return $facts;
  }

}
