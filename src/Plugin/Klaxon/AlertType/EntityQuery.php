<?php

declare(strict_types=1);

namespace Drupal\klaxon\Plugin\Klaxon\AlertType;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
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
   * Every condition has to match.
   */
  protected const CONJUNCTION_AND = 'AND';

  /**
   * Any one condition matching is enough.
   */
  protected const CONJUNCTION_OR = 'OR';

  /**
   * Operators that ask about the field itself, so take no value.
   */
  protected const VALUELESS_OPERATORS = ['IS NULL', 'IS NOT NULL'];

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'entity_type' => '',
      'bundle' => '',
      // Each condition is ['field' => …, 'operator' => '=', 'value' => …].
      'conditions' => [],
      'conjunction' => self::CONJUNCTION_AND,
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
    $form['measure'] = $this->measureForm($form_state);
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

    if (array_key_exists('conditions', $values)) {
      $section = (array) $values['conditions'];

      $values['conditions'] = $this->cleanConditions((array) ($section['rows'] ?? []));
      $values['conjunction'] = ($section['conjunction'] ?? self::CONJUNCTION_AND) === self::CONJUNCTION_OR
        ? self::CONJUNCTION_OR
        : self::CONJUNCTION_AND;
    }

    return $values;
  }

  /**
   * Trims the submitted condition rows down to the ones that say something.
   *
   * @param array $rows
   *   Rows as the form submitted them, blanks and all.
   *
   * @return array<int, array<string, string>>
   *   Rows with a field, renumbered so the blanks leave no holes.
   */
  protected function cleanConditions(array $rows): array {
    $conditions = [];

    foreach ($rows as $row) {
      $field = trim((string) ($row['field'] ?? ''));
      if ($field === '') {
        continue;
      }

      $operator = (string) ($row['operator'] ?? '=');
      $conditions[] = [
        'field' => $field,
        'operator' => $operator,
        // An operator asking whether the field is there has nothing to compare
        // against, and a leftover value would only be misread later.
        'value' => in_array($operator, static::VALUELESS_OPERATORS, TRUE) ? '' : trim((string) ($row['value'] ?? '')),
      ];
    }

    return $conditions;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::validateConfigurationForm($form, $form_state);

    foreach ((array) $form_state->getValue(['measure', 'conditions', 'rows']) as $delta => $row) {
      $field = trim((string) ($row['field'] ?? ''));
      $value = trim((string) ($row['value'] ?? ''));
      $operator = (string) ($row['operator'] ?? '=');

      if ($field === '') {
        if ($value !== '') {
          $form_state->setErrorByName("measure][conditions][rows][$delta][field", $this->t('Name the field %value belongs to, or clear it.', ['%value' => $value]));
        }
        continue;
      }

      if ($value === '' && !in_array($operator, static::VALUELESS_OPERATORS, TRUE)) {
        $form_state->setErrorByName("measure][conditions][rows][$delta][value", $this->t('Give %field something to match, or ask whether it is empty.', ['%field' => $field]));
      }
    }
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
  protected function measureForm(FormStateInterface $form_state): array {
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
      // Both the bundle list and the condition rows' field lists are built
      // from whatever is chosen here, so both are replaced together.
      '#ajax' => [
        'callback' => [static::class, 'updateForEntityType'],
        'wrapper' => $this->bundlesWrapperId(),
      ],
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

    // Lives inside this section so its values are flattened onto the keys the
    // query already reads.
    $form['conditions'] = $this->conditionsForm($form_state);

    return $form;
  }

  /**
   * Which ones to count, beyond their type.
   *
   * Rows are kept in form state so the Add and Remove buttons have something
   * to grow and shrink, which they could not have while this form was itself
   * rebuilt by the AJAX behind the alert type picker. Now the type is settled
   * a step earlier and these are ordinary buttons.
   */
  protected function conditionsForm(FormStateInterface $form_state): array {
    $rows = $this->conditionRows($form_state);
    $wrapper_id = $this->conditionsWrapperId();

    $form = [
      '#type' => 'details',
      '#title' => $this->t('Only the ones matching'),
      '#open' => $this->savedConditions() !== [],
      '#attributes' => ['id' => $wrapper_id],
      // The fields on offer are the chosen entity type's, which is a sibling
      // select: its value is only known once Drupal walks the form.
      '#process' => [[static::class, 'processConditions']],
    ];

    $form['conjunction'] = [
      '#type' => 'radios',
      '#title' => $this->t('Count the ones where'),
      '#options' => [
        self::CONJUNCTION_AND => $this->t('every condition matches'),
        self::CONJUNCTION_OR => $this->t('any condition matches'),
      ],
      '#default_value' => $this->configuration['conjunction'] ?? self::CONJUNCTION_AND,
      '#access' => count($rows) > 1,
    ];

    $form['rows'] = [
      '#type' => 'container',
    ];

    foreach ($rows as $delta => $condition) {
      $form['rows'][$delta] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['container-inline']],
      ];
      $form['rows'][$delta]['field'] = [
        '#type' => 'select',
        '#title' => $this->t('Field'),
        // The titles are the column headings, so only the first row shows them.
        '#title_display' => $delta === 0 ? 'before' : 'invisible',
        '#default_value' => (string) ($condition['field'] ?? ''),
        // Filled in by ::processConditions(), which knows the entity type.
        '#options' => [],
        '#empty_option' => $this->t('- None -'),
        '#empty_value' => '',
      ];
      $form['rows'][$delta]['operator'] = [
        '#type' => 'select',
        '#title' => $this->t('Test'),
        '#title_display' => $delta === 0 ? 'before' : 'invisible',
        '#options' => $this->conditionOperatorOptions(),
        '#default_value' => (string) ($condition['operator'] ?? '='),
      ];
      $form['rows'][$delta]['value'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Value'),
        '#title_display' => $delta === 0 ? 'before' : 'invisible',
        '#default_value' => (string) ($condition['value'] ?? ''),
        '#size' => 30,
        '#placeholder' => 'approve_verify',
      ];
      $form['rows'][$delta]['remove'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove'),
        // Unique, or every row submits as the same button.
        '#name' => 'klaxon-condition-remove-' . $this->getPluginId() . '-' . $delta,
        '#submit' => [[static::class, 'removeCondition']],
        '#ajax' => [
          'callback' => [static::class, 'refreshConditions'],
          'wrapper' => $wrapper_id,
        ],
        // Nothing here is an answer worth validating, and a half-filled row
        // elsewhere on the step must not stop you deleting this one. The rows
        // are read back from the raw input instead.
        '#limit_validation_errors' => [],
        '#klaxon_delta' => $delta,
        '#klaxon_rows_key' => $this->conditionRowsKey(),
        '#access' => count($rows) > 1,
      ];
    }

    $form['add'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add condition'),
      '#name' => 'klaxon-condition-add-' . $this->getPluginId(),
      '#submit' => [[static::class, 'addCondition']],
      '#ajax' => [
        'callback' => [static::class, 'refreshConditions'],
        'wrapper' => $wrapper_id,
      ],
      '#limit_validation_errors' => [],
      '#klaxon_rows_key' => $this->conditionRowsKey(),
    ];

    return $form;
  }

  /**
   * The rows to render: what is being edited, or what was saved.
   */
  protected function conditionRows(FormStateInterface $form_state): array {
    $rows = $form_state->get($this->conditionRowsKey());

    if (!is_array($rows)) {
      // One empty row to write in, so the section is never a dead end.
      $rows = $this->savedConditions() ?: [['field' => '', 'operator' => '=', 'value' => '']];
      $form_state->set($this->conditionRowsKey(), $rows);
    }

    return array_values($rows);
  }

  /**
   * Where the rows being edited are kept between rebuilds.
   */
  protected function conditionRowsKey(): string {
    return 'klaxon_condition_rows:' . $this->getPluginId();
  }

  /**
   * Adds an empty row below the ones already there.
   */
  public static function addCondition(array &$form, FormStateInterface $form_state): void {
    $rows = static::rowsInProgress($form_state);
    $rows[] = ['field' => '', 'operator' => '=', 'value' => ''];

    static::rebuildWithRows($form_state, $rows);
  }

  /**
   * Takes one row away.
   */
  public static function removeCondition(array &$form, FormStateInterface $form_state): void {
    $rows = static::rowsInProgress($form_state);
    unset($rows[$form_state->getTriggeringElement()['#klaxon_delta']]);

    static::rebuildWithRows($form_state, array_values($rows));
  }

  /**
   * Rebuilds the step with the rows as they now are.
   *
   * The raw input is rewritten along with them. A rebuilt form takes an
   * element's value from the input over its default, so removing the first of
   * two rows would otherwise redraw the survivor holding the deleted row's
   * answers - the row is gone, the typing stayed where it was.
   */
  protected static function rebuildWithRows(FormStateInterface $form_state, array $rows): void {
    $button = $form_state->getTriggeringElement();

    $form_state->set($button['#klaxon_rows_key'], $rows);

    $input = $form_state->getUserInput();
    NestedArray::setValue($input, array_merge(static::conditionsParents($button), ['rows']), $rows);
    $form_state->setUserInput($input);

    $form_state->setRebuild();
  }

  /**
   * Where the conditions section sits, read back from one of its buttons.
   *
   * @return string[]
   *   The parents of the section, however deep the subform was embedded.
   */
  protected static function conditionsParents(array $button): array {
    $parents = $button['#parents'];

    while ($parents !== [] && end($parents) !== 'conditions') {
      array_pop($parents);
    }

    return $parents;
  }

  /**
   * AJAX callback returning the conditions section as it now stands.
   */
  public static function refreshConditions(array $form, FormStateInterface $form_state): array {
    $parents = $form_state->getTriggeringElement()['#array_parents'];

    // Both buttons live inside the section being replaced, the Remove ones
    // two levels further in.
    while ($parents !== [] && end($parents) !== 'conditions') {
      array_pop($parents);
    }

    return NestedArray::getValue($form, $parents);
  }

  /**
   * The rows as they are on screen, typed values and all.
   *
   * Read from the raw input rather than from the submitted values: these
   * buttons validate nothing, so there are no values to read.
   */
  protected static function rowsInProgress(FormStateInterface $form_state): array {
    $parents = static::conditionsParents($form_state->getTriggeringElement());
    $rows = NestedArray::getValue($form_state->getUserInput(), array_merge($parents, ['rows']));

    return is_array($rows) ? array_values($rows) : [];
  }

  /**
   * The operators a condition row can be tested with.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Operators as the entity query takes them, keyed by what they do.
   */
  protected function conditionOperatorOptions(): array {
    return [
      '=' => $this->t('is'),
      '<>' => $this->t('is not'),
      'IN' => $this->t('is one of (comma separated)'),
      'NOT IN' => $this->t('is none of (comma separated)'),
      '>' => $this->t('is more than'),
      '>=' => $this->t('is at least'),
      '<' => $this->t('is less than'),
      '<=' => $this->t('is at most'),
      'CONTAINS' => $this->t('contains'),
      'STARTS_WITH' => $this->t('starts with'),
      'ENDS_WITH' => $this->t('ends with'),
      'IS NULL' => $this->t('is empty'),
      'IS NOT NULL' => $this->t('is not empty'),
    ];
  }

  /**
   * The DOM id of the container holding the condition rows.
   */
  protected function conditionsWrapperId(): string {
    return 'klaxon-conditions-' . str_replace('_', '-', $this->getPluginId());
  }

  /**
   * Fills each row's field select with the chosen entity type's fields.
   *
   * Runs while Drupal walks the form, so the entity type select - declared
   * before this - already has its value, submitted or stored.
   */
  public static function processConditions(array &$element, FormStateInterface $form_state, array &$complete_form): array {
    $parents = $element['#parents'];
    array_pop($parents);
    $parents[] = 'entity_type';

    $entity_type_id = (string) ($form_state->getValue($parents) ?? '');
    $options = static::fieldOptions($entity_type_id);

    $rows = $element['rows'] ?? [];

    foreach (Element::children($rows) as $delta) {
      $saved = (string) ($element['rows'][$delta]['field']['#default_value'] ?? '');

      // A field the entity type no longer has, or one written by hand with a
      // property on it, would otherwise vanish from its own row on save.
      $element['rows'][$delta]['field']['#options'] = $saved !== '' && !isset($options[$saved])
        ? $options + [$saved => $saved]
        : $options;
    }

    return $element;
  }

  /**
   * AJAX callback replacing everything built from the entity type.
   */
  public static function updateForEntityType(array $form, FormStateInterface $form_state): AjaxResponse {
    $parents = $form_state->getTriggeringElement()['#array_parents'];
    array_pop($parents);

    $response = new AjaxResponse();

    // Siblings of the select that triggered this, wherever in the form the
    // subform happens to be embedded.
    foreach (['bundles_wrapper', 'conditions'] as $sibling) {
      $element = NestedArray::getValue($form, array_merge($parents, [$sibling]));

      if (is_array($element) && isset($element['#attributes']['id'])) {
        $response->addCommand(new ReplaceCommand('#' . $element['#attributes']['id'], $element));
      }
    }

    return $response;
  }

  /**
   * The fields of an entity type, as a select's options.
   *
   * Static, because the process callback that needs it cannot carry a plugin
   * instance through a cached form array.
   *
   * @param string $entity_type_id
   *   The entity type to list fields for.
   *
   * @return array<string, string>
   *   Field labels keyed by machine name, machine name included in the label:
   *   a condition is written against the name, and several fields read the
   *   same ("Title", "Name") until you can see it.
   */
  protected static function fieldOptions(string $entity_type_id): array {
    if ($entity_type_id === '' || !\Drupal::entityTypeManager()->hasDefinition($entity_type_id)) {
      return [];
    }

    $options = [];

    foreach (\Drupal::service('entity_field.manager')->getFieldStorageDefinitions($entity_type_id) as $name => $definition) {
      $options[$name] = sprintf('%s (%s)', (string) $definition->getLabel(), $name);
    }

    ksort($options);

    return $options;
  }

  /**
   * The saved conditions, without the rows left blank.
   *
   * @return array<int, array<string, string>>
   *   Condition rows, renumbered from zero.
   */
  protected function savedConditions(): array {
    return array_values(array_filter(
      (array) ($this->configuration['conditions'] ?? []),
      static fn ($condition): bool => is_array($condition) && trim((string) ($condition['field'] ?? '')) !== '',
    ));
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

    $conditions = $this->savedConditions();

    // "Any of them" is a group of its own, so it stays one term of the query
    // rather than loosening everything else - the bundle and the date window
    // are still required alongside it.
    $group = ($this->configuration['conjunction'] ?? self::CONJUNCTION_AND) === self::CONJUNCTION_OR && count($conditions) > 1
      ? $query->orConditionGroup()
      : $query;

    foreach ($conditions as $condition) {
      $field = trim((string) $condition['field']);
      $operator = (string) ($condition['operator'] ?? '=');
      $value = $condition['value'] ?? NULL;

      // Whether a field holds anything is its own question in an entity query,
      // not an operator it would recognise.
      if (in_array($operator, static::VALUELESS_OPERATORS, TRUE)) {
        $operator === 'IS NULL' ? $group->notExists($field) : $group->exists($field);
        continue;
      }

      if ($operator === 'IN' || $operator === 'NOT IN') {
        $value = array_values(array_filter(
          array_map('trim', explode(',', (string) $value)),
          static fn (string $item): bool => $item !== '',
        ));

        // A list with nothing in it would match nothing at all, which is not
        // what an unfinished row is asking for.
        if ($value === []) {
          continue;
        }
      }

      $group->condition($field, $value, $operator);
    }

    if ($group !== $query) {
      $query->condition($group);
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
