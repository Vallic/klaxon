<?php

declare(strict_types=1);

namespace Drupal\klaxon\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityWithPluginCollectionInterface;
use Drupal\Core\Plugin\DefaultSingleLazyPluginCollection;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\klaxon\AlertListBuilder;
use Drupal\klaxon\AlertType\AlertTypeInterface;
use Drupal\klaxon\Form\AlertForm;
use Drupal\klaxon\Message;

/**
 * One thing worth knowing about, and what to do when it becomes true.
 */
#[ConfigEntityType(
  id: 'klaxon_alert',
  label: new TranslatableMarkup('Alert'),
  label_collection: new TranslatableMarkup('Alerts'),
  label_singular: new TranslatableMarkup('alert'),
  label_plural: new TranslatableMarkup('alerts'),
  config_prefix: 'alert',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'status' => 'status',
  ],
  handlers: [
    'list_builder' => AlertListBuilder::class,
    'form' => [
      'default' => AlertForm::class,
      'add' => AlertForm::class,
      'edit' => AlertForm::class,
      'delete' => EntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/config/system/klaxon/alerts',
    'add-form' => '/admin/config/system/klaxon/alerts/add',
    'edit-form' => '/admin/config/system/klaxon/alerts/{klaxon_alert}',
    'delete-form' => '/admin/config/system/klaxon/alerts/{klaxon_alert}/delete',
  ],
  admin_permission: 'administer klaxon alerts',
  label_count: [
    'singular' => '@count alert',
    'plural' => '@count alerts',
  ],
  config_export: [
    'id',
    'label',
    'description',
    'type',
    'channels',
    'notify_on',
    'cooldown',
    'per_row',
    'severity',
    'subject',
    'body',
  ],
)]
class Alert extends ConfigEntityBase implements AlertInterface, EntityWithPluginCollectionInterface {

  /**
   * The alert ID.
   */
  protected string $id;

  /**
   * The human-readable label.
   */
  protected string $label;

  /**
   * What this alert is for.
   */
  protected string $description = '';

  /**
   * Alert type plugin configuration, including its "id" key.
   */
  protected array $type = [];

  /**
   * IDs of the channels this alert delivers to.
   */
  protected array $channels = [];

  /**
   * One of the NOTIFY_* constants.
   */
  protected string $notify_on = self::NOTIFY_CHANGE;

  /**
   * Seconds of silence after firing. Zero disables the cooldown.
   */
  protected int $cooldown = 0;

  /**
   * Whether each matching row is reported at most once, ever.
   */
  protected bool $per_row = FALSE;

  /**
   * One of the Message::SEVERITY_* constants.
   */
  protected string $severity = Message::SEVERITY_WARNING;

  /**
   * Subject template. Falls back to the label when empty.
   */
  protected string $subject = '';

  /**
   * Body template. A default body is built when empty.
   */
  protected string $body = '';

  /**
   * Lazily instantiated alert type plugin.
   */
  protected ?DefaultSingleLazyPluginCollection $typeCollection = NULL;

  /**
   * {@inheritdoc}
   */
  public function getType(): AlertTypeInterface {
    $type = $this->typeCollection()->get($this->getTypeId());
    assert($type instanceof AlertTypeInterface);
    return $type;
  }

  /**
   * {@inheritdoc}
   */
  public function getTypeId(): string {
    return (string) ($this->type['id'] ?? 'entity_query');
  }

  /**
   * {@inheritdoc}
   */
  public function getChannelIds(): array {
    return array_values($this->channels);
  }

  /**
   * {@inheritdoc}
   */
  public function getNotifyOn(): string {
    return $this->notify_on;
  }

  /**
   * {@inheritdoc}
   */
  public function getCooldown(): int {
    return $this->cooldown;
  }

  /**
   * {@inheritdoc}
   */
  public function isPerRow(): bool {
    return $this->per_row;
  }

  /**
   * {@inheritdoc}
   */
  public function getSeverity(): string {
    return $this->severity;
  }

  /**
   * {@inheritdoc}
   */
  public function getSubjectTemplate(): string {
    return $this->subject !== '' ? $this->subject : $this->label();
  }

  /**
   * {@inheritdoc}
   */
  public function getBodyTemplate(): string {
    return $this->body;
  }

  /**
   * {@inheritdoc}
   */
  public function set($property_name, $value) {
    $result = parent::set($property_name, $value);

    // Discard the cached collection so the next access rebuilds it from the
    // value just written. It has to happen after the parent call, because
    // ConfigEntityBase::set() reads the collection before assigning the
    // property: resetting first would only rebuild it from the old plugin ID
    // and a changed alert type would be silently ignored.
    if ($property_name === 'type') {
      $this->typeCollection = NULL;
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginCollections(): array {
    return ['type' => $this->typeCollection()];
  }

  /**
   * The lazily built alert type plugin collection.
   */
  protected function typeCollection(): DefaultSingleLazyPluginCollection {
    if ($this->typeCollection === NULL) {
      $this->typeCollection = new DefaultSingleLazyPluginCollection(
        \Drupal::service('plugin.manager.klaxon_alert_type'),
        $this->getTypeId(),
        $this->type,
      );
    }

    return $this->typeCollection;
  }

}
