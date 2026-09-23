<?php

declare(strict_types=1);

namespace Drupal\klaxon\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\klaxon\Alert\MessageRenderer;
use Drupal\klaxon\AlertType\AlertTypeInterface;
use Drupal\klaxon\AlertType\AlertTypeManager;
use Drupal\klaxon\Entity\AlertInterface;
use Drupal\klaxon\Message;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Add and edit form for alerts, asked one step at a time.
 *
 * One question decides the shape of the form: what kind of alert is this. The
 * type plugin then asks for whatever it actually needs, and everything after
 * it — channels, how often to repeat, the message — is the same whatever was
 * picked. That is the order the steps follow.
 *
 * Steps rather than one page because the middle one belongs to a plugin, and
 * on one page it could only be swapped in by AJAX. Everything that plugin
 * wanted to build from a value of its own — a field list that follows the
 * chosen entity type, a row you can add another of — then had to work inside
 * that replaced region, nested in the AJAX that put it there. With the type
 * settled a step earlier, the plugin's form is just a form.
 *
 * Editing is stepped too: the same three questions in the same order, with
 * Save on every step, so a change to one of them is one step and one click.
 */
class AlertForm extends EntityForm {

  use PluginPickerTrait;

  /**
   * What each step asks about, in order.
   */
  protected const STEPS = ['basics', 'settings', 'delivery'];

  public function __construct(
    protected readonly AlertTypeManager $alertTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('plugin.manager.klaxon_alert_type'));
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $step = $this->step($form_state);

    $form['klaxon_progress'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Step @step of @total: @title', [
        '@step' => $step + 1,
        '@total' => count(self::STEPS),
        '@title' => $this->stepTitle($step),
      ]),
      '#attributes' => ['class' => ['klaxon-step']],
      '#weight' => -100,
    ];

    match (self::STEPS[$step]) {
      'basics' => $this->basicsStep($form, $form_state),
      'settings' => $this->settingsStep($form, $form_state),
      'delivery' => $this->deliveryStep($form, $form_state),
    };

    return $form;
  }

  /**
   * Which alert this is, and what kind.
   */
  protected function basicsStep(array &$form, FormStateInterface $form_state): void {
    $alert = $this->entity;
    assert($alert instanceof AlertInterface);

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $alert->label(),
      '#required' => TRUE,
      '#description' => $this->t('Write it as the thing you want to hear, such as %example.', [
        '%example' => 'No orders in the last 30 minutes',
      ]),
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $alert->id(),
      '#machine_name' => ['exists' => [$this, 'exists']],
      '#disabled' => !$alert->isNew(),
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $alert->isNew() ? TRUE : $alert->status(),
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#rows' => 2,
      '#default_value' => $alert->get('description') ?? '',
      '#description' => $this->t('For whoever finds this alert later and wonders why it exists.'),
    ];

    $form['type_id'] = [
      '#type' => 'select',
      '#title' => $this->t('This alert is'),
      '#options' => $this->pluginOptions($this->alertTypeManager),
      '#default_value' => $this->typeId($form_state),
      '#description' => $this->t('What it asks next depends on this. Changing it on an existing alert starts its settings again from the defaults of the type picked.'),
    ];
  }

  /**
   * Whatever the chosen type needs to know.
   */
  protected function settingsStep(array &$form, FormStateInterface $form_state): void {
    $plugin = $this->typePlugin($form_state);
    $description = (string) ($this->alertTypeManager->getDefinition($plugin->getPluginId())['description'] ?? '');

    if ($description !== '') {
      $form['type_description'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $description,
        '#attributes' => ['class' => ['klaxon-type-description']],
      ];
    }

    // A container of its own, so nothing this form adds can collide with a
    // setting the plugin happens to call the same thing.
    $form['type_settings'] = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];

    $subform_state = SubformState::createForSubform($form['type_settings'], $form, $form_state);
    $form['type_settings'] = $plugin->buildConfigurationForm($form['type_settings'], $subform_state) + $form['type_settings'];
  }

  /**
   * Where it goes, how often, and what it says.
   */
  protected function deliveryStep(array &$form, FormStateInterface $form_state): void {
    $alert = $this->entity;
    assert($alert instanceof AlertInterface);

    $form['delivery'] = [
      '#type' => 'details',
      '#title' => $this->t('Delivery'),
      '#open' => TRUE,
    ];

    $channels = [];
    foreach ($this->entityTypeManager->getStorage('klaxon_channel')->loadMultiple() as $id => $channel) {
      $channels[$id] = $channel->label();
    }

    $form['delivery']['channels'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Send to'),
      '#options' => $channels,
      '#default_value' => $alert->getChannelIds(),
      '#description' => $channels === []
        ? $this->t('No channels exist yet. Add one first, or this alert has nowhere to go.')
        : $this->t('An alert with no channel still runs and still records its state. It just says nothing.'),
    ];

    $form['delivery']['notify_on'] = [
      '#type' => 'radios',
      '#title' => $this->t('How often'),
      '#options' => [
        AlertInterface::NOTIFY_CHANGE => $this->t('Once, when it starts'),
        AlertInterface::NOTIFY_CHANGE_AND_RECOVERY => $this->t('Once when it starts, and again when it clears'),
        AlertInterface::NOTIFY_EVERY => $this->t('Every time it is checked and still true'),
      ],
      '#default_value' => $alert->getNotifyOn(),
    ];

    $form['delivery']['cooldown'] = [
      '#type' => 'number',
      '#title' => $this->t('Stay quiet afterwards for'),
      '#field_suffix' => $this->t('seconds'),
      '#min' => 0,
      '#default_value' => $alert->getCooldown(),
      '#description' => $this->t('Zero means no cooldown. Useful for a value sitting right on the threshold.'),
    ];

    $form['delivery']['per_row'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Report each match only once, ever'),
      '#default_value' => $alert->isPerRow(),
      '#description' => $this->t('Keeps a record of what it has already mentioned. This is what makes "ends within the hour" announce each one once, rather than on every check for the rest of that hour.'),
    ];

    $form['delivery']['severity'] = [
      '#type' => 'select',
      '#title' => $this->t('Severity'),
      '#options' => [
        Message::SEVERITY_INFO => $this->t('Information'),
        Message::SEVERITY_WARNING => $this->t('Warning'),
        Message::SEVERITY_CRITICAL => $this->t('Critical'),
      ],
      '#default_value' => $alert->getSeverity(),
    ];

    $form['message'] = [
      '#type' => 'details',
      '#title' => $this->t('Message'),
      '#open' => FALSE,
      '#description' => $this->t('What the alert says when it fires. Leave either field empty and one is written for you.'),
    ];

    // Named in full, because "count" and "value" are not the same number and
    // the difference only shows up in a message that has already gone out:
    // an alert counting orders reads its total from value, and has no rows
    // for count to count.
    $form['message']['placeholders'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Placeholders'),
      '#weight' => -10,
      '#items' => [
        $this->t('<code>[klaxon:label]</code> — this alert&#039;s name.'),
        $this->t('<code>[klaxon:value]</code> — the number it measured: the count, the total it added up, or how many rows it matched. This is the one that answers "how many".'),
        $this->t('<code>[klaxon:count]</code> — how many rows it lists. An alert that measures a number instead of listing rows has none, so this reads 0 — use <code>[klaxon:value]</code> there.'),
        $this->t('<code>[klaxon:rows]</code> — those rows, one per line, up to @max of them and then "and N more". Empty for an alert that only measures a number.', [
          '@max' => MessageRenderer::MAX_LISTED_ROWS,
        ]),
        $this->t('Entity tokens, such as <code>[commerce_order:order_number]</code>, when the alert is about one thing — one watching a saved entity, rather than counting many.'),
      ],
    ];

    $form['message']['subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#default_value' => $alert->get('subject') ?? '',
      '#description' => $this->t('Leave empty to use the alert name.'),
    ];

    $form['message']['body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Body'),
      '#rows' => 4,
      '#default_value' => $alert->get('body') ?? '',
      '#description' => $this->t('Leave empty and a sensible default is written for you.'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state): array {
    $actions = parent::actions($form, $form_state);
    $step = $this->step($form_state);
    $last = $step === count(self::STEPS) - 1;

    // A new alert is only worth saving once it has been through every step:
    // one without a type has nothing to run. An existing one is already whole,
    // so every step can be saved from and left.
    if (!$last && $this->entity->isNew()) {
      unset($actions['submit']);
    }
    elseif (!$last) {
      $actions['submit']['#value'] = $this->t('Save');
      $actions['submit']['#button_type'] = 'secondary';
    }

    if ($step > 0) {
      $actions['back'] = [
        '#type' => 'submit',
        '#value' => $this->t('Back'),
        '#submit' => ['::changeStep'],
        '#klaxon_step' => $step - 1,
        // Going back is not an answer to anything on this step.
        '#limit_validation_errors' => [],
        '#weight' => -10,
      ];
    }

    if (!$last) {
      $actions['next'] = [
        '#type' => 'submit',
        '#value' => $this->t('Next'),
        '#button_type' => 'primary',
        '#submit' => ['::changeStep'],
        '#klaxon_step' => $step + 1,
        '#weight' => 10,
      ];
    }

    return $actions;
  }

  /**
   * Keeps this step's answers and moves to another one.
   */
  public function changeStep(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();

    // Back discards nothing, but it has skipped validation, so there is
    // nothing worth keeping from a step that was never checked.
    if ((int) $trigger['#klaxon_step'] > $this->step($form_state)) {
      $this->applyStep($form, $form_state);
    }

    $form_state->set('klaxon_step', (int) $trigger['#klaxon_step']);
    $form_state->setRebuild();
  }

  /**
   * Machine name uniqueness check.
   */
  public function exists(string $id): bool {
    return (bool) $this->entityTypeManager->getStorage('klaxon_alert')->load($id);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    if (self::STEPS[$this->step($form_state)] !== 'settings') {
      return;
    }

    $settings = &$form['type_settings'];
    $subform_state = SubformState::createForSubform($settings, $form, $form_state);
    $this->typePlugin($form_state)->validateConfigurationForm($settings, $subform_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    assert($entity instanceof AlertInterface);

    // The default copies every submitted value onto the entity, which would
    // make the type picker and its settings subform into dynamic properties
    // of the alert. Each step writes only what it asked about, so the steps
    // that have not been shown leave what is already stored alone.
    match (self::STEPS[$this->step($form_state)]) {
      'basics' => $this->copyBasics($entity, $form_state),
      'settings' => $this->copySettings($entity, $form, $form_state),
      'delivery' => $this->copyDelivery($entity, $form_state),
    };
  }

  /**
   * Writes the first step onto the alert.
   */
  protected function copyBasics(AlertInterface $alert, FormStateInterface $form_state): void {
    foreach (['id', 'label', 'status', 'description'] as $key) {
      $alert->set($key, $form_state->getValue($key));
    }

    // Only the id is settled here. Picking a different type throws away
    // settings that meant something else; keeping the same one keeps them,
    // so coming back to this step and leaving it alone changes nothing.
    $stored = (array) ($alert->get('type') ?? []);
    $chosen = (string) $form_state->getValue('type_id');

    $alert->set('type', ['id' => $chosen] + ((string) ($stored['id'] ?? '') === $chosen ? $stored : []));
  }

  /**
   * Writes the plugin's own step onto the alert.
   */
  protected function copySettings(AlertInterface $alert, array $form, FormStateInterface $form_state): void {
    $plugin = $this->typePlugin($form_state);
    $settings = &$form['type_settings'];
    $subform_state = SubformState::createForSubform($settings, $form, $form_state);
    $plugin->submitConfigurationForm($settings, $subform_state);

    $alert->set('type', ['id' => $plugin->getPluginId()] + $plugin->getConfiguration());
  }

  /**
   * Writes the last step onto the alert.
   */
  protected function copyDelivery(AlertInterface $alert, FormStateInterface $form_state): void {
    $alert->set('channels', array_values(array_filter((array) $form_state->getValue('channels'))));

    foreach (['notify_on', 'cooldown', 'per_row', 'severity', 'subject', 'body'] as $name) {
      $alert->set($name, $form_state->getValue($name));
    }
  }

  /**
   * Keeps the current step's answers on the entity between rebuilds.
   */
  protected function applyStep(array $form, FormStateInterface $form_state): void {
    $this->entity = $this->buildEntity($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = $this->entity->save();

    $this->messenger()->addStatus($this->t('Alert %label saved.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));

    return $result;
  }

  /**
   * The step being shown, as an index into ::STEPS.
   */
  protected function step(FormStateInterface $form_state): int {
    $step = (int) ($form_state->get('klaxon_step') ?? 0);

    return max(0, min($step, count(self::STEPS) - 1));
  }

  /**
   * What to call a step in the progress line.
   */
  protected function stepTitle(int $step): string {
    return (string) match (self::STEPS[$step]) {
      'basics' => $this->t('What this alert is'),
      'settings' => $this->t('What it watches'),
      'delivery' => $this->t('Where it goes'),
    };
  }

  /**
   * The alert type currently selected, submitted value winning over stored.
   */
  protected function typeId(FormStateInterface $form_state): string {
    $submitted = $form_state->getValue('type_id');

    if (is_string($submitted) && $submitted !== '' && $this->alertTypeManager->hasDefinition($submitted)) {
      return $submitted;
    }

    $alert = $this->entity;
    assert($alert instanceof AlertInterface);

    $stored = $alert->get('type') ?? [];
    $id = (string) ($stored['id'] ?? '');

    if ($id !== '') {
      return $id;
    }

    $definitions = $this->alertTypeManager->getDefinitions();

    return isset($definitions['entity_query']) ? 'entity_query' : (string) array_key_first($definitions);
  }

  /**
   * The plugin instance the settings step is built from.
   */
  protected function typePlugin(FormStateInterface $form_state): AlertTypeInterface {
    $id = $this->typeId($form_state);
    $alert = $this->entity;
    assert($alert instanceof AlertInterface);

    $stored = $alert->get('type') ?? [];

    // Picking a different type starts from that type's own defaults rather
    // than inheriting settings that meant something else.
    if ((string) ($stored['id'] ?? '') !== $id) {
      $stored = [];
    }

    $plugin = $this->alertTypeManager->createInstance($id, $stored);
    assert($plugin instanceof AlertTypeInterface);

    return $plugin;
  }

}
