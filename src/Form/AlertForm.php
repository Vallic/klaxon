<?php

declare(strict_types=1);

namespace Drupal\klaxon\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\klaxon\AlertType\AlertTypeInterface;
use Drupal\klaxon\AlertType\AlertTypeManager;
use Drupal\klaxon\Entity\AlertInterface;
use Drupal\klaxon\Message;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Add and edit form for alerts.
 *
 * One question decides the shape of the form: what kind of alert is this. The
 * type plugin then asks for whatever it actually needs, and everything below
 * it — channels, how often to repeat, the message — is the same whatever was
 * picked.
 */
class AlertForm extends EntityForm {

  use PluginPickerTrait;

  /**
   * The DOM id AJAX replaces when the alert type changes.
   */
  protected const WRAPPER_ID = 'klaxon-type-settings';

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

    $this->typeSection($form, $form_state);

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
      '#description' => $this->t('Placeholders: @label, @value, @count and @rows. Entity tokens work too when the alert is about one thing.', [
        '@label' => '[klaxon:label]',
        '@value' => '[klaxon:value]',
        '@count' => '[klaxon:count]',
        '@rows' => '[klaxon:rows]',
      ]),
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

    return $form;
  }

  /**
   * The type picker and whatever settings the chosen type asks for.
   */
  protected function typeSection(array &$form, FormStateInterface $form_state): void {
    $form['type'] = [
      '#type' => 'details',
      '#title' => $this->t('What kind of alert'),
      '#open' => TRUE,
    ];

    $selected = $this->typeId($form_state);

    $form['type']['type_id'] = [
      '#type' => 'select',
      '#title' => $this->t('This alert is'),
      '#options' => $this->pluginOptions($this->alertTypeManager),
      '#default_value' => $selected,
      '#ajax' => [
        'callback' => '::updateSettings',
        'wrapper' => self::WRAPPER_ID,
      ],
      // Changing the picker must not validate the rest of the form. Without
      // this, choosing a type runs the chosen type's own validation against
      // a form the user has not filled in yet - and against the settings
      // subform of the type they are switching away from, which does not
      // have the elements the new type's validation reaches for.
      '#limit_validation_errors' => [],
    ];

    // Everything the chosen type contributes is replaced together when the
    // select changes: its explanation, and its settings.
    $form['type']['chosen'] = [
      '#type' => 'container',
      '#prefix' => '<div id="' . self::WRAPPER_ID . '">',
      '#suffix' => '</div>',
    ];

    // A select cannot carry a description per option the way radios could, so
    // the chosen type explains itself above its own settings.
    $description = (string) ($this->alertTypeManager->getDefinition($selected)['description'] ?? '');

    if ($description !== '') {
      $form['type']['chosen']['description'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $description,
        '#attributes' => ['class' => ['klaxon-type-description']],
      ];
    }

    // The settings container stays a direct child rather than being merged
    // into the one above, so nothing this form adds can collide with a
    // setting the plugin happens to call the same thing.
    $wrapper = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];

    $form['type']['chosen']['type_settings'] = $wrapper;

    $subform_state = SubformState::createForSubform($form['type']['chosen']['type_settings'], $form, $form_state);
    $built = $this->typePlugin($form_state)
      ->buildConfigurationForm($form['type']['chosen']['type_settings'], $subform_state);

    // Stamped with the type it was built for. On the request that changes
    // the select, the form being validated is still the previous type's,
    // and running the new type's validation against it is meaningless.
    $form['type']['chosen']['type_settings'] = $built + $wrapper;
    $form['type']['chosen']['type_settings']['#klaxon_type'] = $this->typePlugin($form_state)->getPluginId();
  }

  /**
   * AJAX callback returning everything the type just picked contributes.
   */
  public function updateSettings(array $form, FormStateInterface $form_state): array {
    return $form['type']['chosen'];
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

    $plugin = $this->typePlugin($form_state);

    // The settings subform belongs to whichever type was selected when the
    // form was built. On the request that changes the select those disagree,
    // and validating the new type against the old type's elements is both
    // meaningless and a fatal - so leave it to the rebuilt form.
    if (($form['type']['chosen']['type_settings']['#klaxon_type'] ?? NULL) !== $plugin->getPluginId()) {
      return;
    }

    $settings = &$form['type']['chosen']['type_settings'];
    $subform_state = SubformState::createForSubform($settings, $form, $form_state);
    $plugin->validateConfigurationForm($settings, $subform_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    assert($entity instanceof AlertInterface);

    // The default copies every submitted value onto the entity, which would
    // make the type picker and its settings subform into dynamic properties
    // of the alert. Only the plain fields belong here; everything else is
    // written by submitForm() once the plugin has had its say.
    foreach (['id', 'label', 'status', 'description'] as $key) {
      $entity->set($key, $form_state->getValue($key));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);
    $alert = $this->entity;
    assert($alert instanceof AlertInterface);

    $plugin = $this->typePlugin($form_state);
    $settings = &$form['type']['chosen']['type_settings'];
    $subform_state = SubformState::createForSubform($settings, $form, $form_state);
    $plugin->submitConfigurationForm($settings, $subform_state);

    $alert->set('type', ['id' => $plugin->getPluginId()] + $plugin->getConfiguration());
    $alert->set('channels', array_values(array_filter((array) $form_state->getValue('channels'))));

    foreach (['notify_on', 'cooldown', 'per_row', 'severity', 'subject', 'body'] as $name) {
      $alert->set($name, $form_state->getValue($name));
    }
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
   * The alert type currently selected, submitted value winning over stored.
   */
  protected function typeId(FormStateInterface $form_state): string {
    $submitted = $form_state->getValue('type_id');

    if (is_string($submitted) && $submitted !== '') {
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
   * The plugin instance the settings subform is built from.
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
