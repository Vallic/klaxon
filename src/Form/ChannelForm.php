<?php

declare(strict_types=1);

namespace Drupal\klaxon\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\klaxon\Entity\ChannelInterface;
use Drupal\klaxon\Transport\TransportInterface;
use Drupal\klaxon\Transport\TransportManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Add and edit form for channels.
 */
class ChannelForm extends EntityForm {

  use PluginPickerTrait;

  /**
   * The DOM id AJAX replaces when the channel type changes.
   */
  protected const WRAPPER_ID = 'klaxon-transport-settings';

  public function __construct(
    protected readonly TransportManager $transportManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('plugin.manager.klaxon_transport'));
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $channel = $this->entity;
    assert($channel instanceof ChannelInterface);

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $channel->label(),
      '#required' => TRUE,
      '#description' => $this->t('What the people picking this channel will recognize, such as %example.', ['%example' => 'Ops chat']),
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $channel->id(),
      '#machine_name' => [
        'exists' => [$this, 'exists'],
      ],
      '#disabled' => !$channel->isNew(),
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $channel->isNew() ? TRUE : $channel->status(),
      '#description' => $this->t('A disabled channel is skipped, and every alert pointing at it keeps working.'),
    ];

    $selected = $this->transportId($form_state);

    $form['transport_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Channel type'),
      '#options' => $this->pluginOptions($this->transportManager),
      '#default_value' => $selected,
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::updateSettings',
        'wrapper' => self::WRAPPER_ID,
      ],
      // Changing the picker must not validate the rest of the form. Without
      // this, choosing a transport runs that transport's own validation
      // against a form the user has not filled in yet - and against the
      // settings subform of the transport they are switching away from,
      // which does not have the elements it reaches for.
      '#limit_validation_errors' => [],
    ];

    // Everything the chosen transport contributes is replaced together when
    // the select changes: its explanation, and its settings.
    $form['chosen'] = [
      '#type' => 'container',
      '#prefix' => '<div id="' . self::WRAPPER_ID . '">',
      '#suffix' => '</div>',
    ];

    $description = (string) ($this->transportManager->getDefinition($selected)['description'] ?? '');

    if ($description !== '') {
      $form['chosen']['description'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $description,
        '#attributes' => ['class' => ['klaxon-transport-description']],
      ];
    }

    // The settings container stays a direct child rather than being merged
    // into the one above, so nothing this form adds can collide with a
    // setting the plugin happens to call the same thing.
    $wrapper = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];

    $form['chosen']['transport_settings'] = $wrapper;

    $subform_state = SubformState::createForSubform($form['chosen']['transport_settings'], $form, $form_state);
    $built = $this->transportPlugin($form_state)
      ->buildConfigurationForm($form['chosen']['transport_settings'], $subform_state);

    // Stamped with the transport it was built for; see validateForm().
    $form['chosen']['transport_settings'] = $built + $wrapper;
    $form['chosen']['transport_settings']['#klaxon_transport'] = $this->transportPlugin($form_state)->getPluginId();

    return $form;
  }

  /**
   * AJAX callback returning everything the type just picked contributes.
   */
  public function updateSettings(array $form, FormStateInterface $form_state): array {
    return $form['chosen'];
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    assert($entity instanceof ChannelInterface);

    // The default copies every submitted value onto the entity, which would
    // make the type picker and its settings subform into dynamic properties
    // of the channel. submitForm() writes what the channel actually keeps.
    foreach (['id', 'label', 'status'] as $key) {
      $entity->set($key, $form_state->getValue($key));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $plugin = $this->transportPlugin($form_state);

    // The settings subform belongs to whichever transport was selected when
    // the form was built. On the request that changes the select those
    // disagree, so leave validation to the rebuilt form.
    if (($form['chosen']['transport_settings']['#klaxon_transport'] ?? NULL) !== $plugin->getPluginId()) {
      return;
    }

    $settings = &$form['chosen']['transport_settings'];
    $subform_state = SubformState::createForSubform($settings, $form, $form_state);
    $plugin->validateConfigurationForm($settings, $subform_state);
  }

  /**
   * Machine name uniqueness check.
   */
  public function exists(string $id): bool {
    return (bool) $this->entityTypeManager->getStorage('klaxon_channel')->load($id);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);

    $channel = $this->entity;
    assert($channel instanceof ChannelInterface);

    $plugin = $this->transportPlugin($form_state);
    $settings = &$form['chosen']['transport_settings'];
    $subform_state = SubformState::createForSubform($settings, $form, $form_state);
    $plugin->submitConfigurationForm($settings, $subform_state);

    $channel->set('transport', ['id' => $plugin->getPluginId()] + $plugin->getConfiguration());
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = $this->entity->save();

    $this->messenger()->addStatus($this->t('Channel %label saved.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));

    return $result;
  }

  /**
   * The transport plugin ID currently selected, falling back to the entity.
   */
  protected function transportId(FormStateInterface $form_state): string {
    $selected = $form_state->getValue('transport_id');

    if (is_string($selected) && $selected !== '') {
      return $selected;
    }

    $channel = $this->entity;
    assert($channel instanceof ChannelInterface);

    return $channel->getTransportId();
  }

  /**
   * The transport plugin to build a settings form for.
   */
  protected function transportPlugin(FormStateInterface $form_state): TransportInterface {
    $id = $this->transportId($form_state);
    $channel = $this->entity;
    assert($channel instanceof ChannelInterface);

    // Keep the saved settings when the form is rebuilt for the same plugin,
    // and start clean when the editor picks a different one.
    $configuration = $channel->getTransportId() === $id
      ? $channel->get('transport') ?? []
      : [];

    $plugin = $this->transportManager->createInstance($id, $configuration);
    assert($plugin instanceof TransportInterface);

    return $plugin;
  }

}
