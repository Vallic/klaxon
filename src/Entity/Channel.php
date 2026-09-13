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
use Drupal\klaxon\ChannelListBuilder;
use Drupal\klaxon\Form\ChannelForm;
use Drupal\klaxon\Transport\TransportInterface;

/**
 * Where alerts get delivered, and the credentials for getting there.
 *
 * Channels are separate from alerts on purpose. Credentials live here and are
 * referenced, never copied, so one workspace connection serves every alert and
 * an exported alert can never carry a secret.
 */
#[ConfigEntityType(
  id: 'klaxon_channel',
  label: new TranslatableMarkup('Klaxon channel'),
  label_collection: new TranslatableMarkup('Klaxon channels'),
  label_singular: new TranslatableMarkup('channel'),
  label_plural: new TranslatableMarkup('channels'),
  config_prefix: 'channel',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'status' => 'status',
  ],
  handlers: [
    'list_builder' => ChannelListBuilder::class,
    'form' => [
      'default' => ChannelForm::class,
      'add' => ChannelForm::class,
      'edit' => ChannelForm::class,
      'delete' => EntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/config/system/klaxon/channels',
    'add-form' => '/admin/config/system/klaxon/channels/add',
    'edit-form' => '/admin/config/system/klaxon/channels/{klaxon_channel}',
    'delete-form' => '/admin/config/system/klaxon/channels/{klaxon_channel}/delete',
  ],
  admin_permission: 'administer klaxon channels',
  label_count: [
    'singular' => '@count channel',
    'plural' => '@count channels',
  ],
  config_export: [
    'id',
    'label',
    'description',
    'transport',
  ],
)]
class Channel extends ConfigEntityBase implements ChannelInterface, EntityWithPluginCollectionInterface {

  /**
   * The channel ID.
   */
  protected string $id;

  /**
   * The human-readable label.
   */
  protected string $label;

  /**
   * What this channel is for.
   */
  protected string $description = '';

  /**
   * Transport plugin configuration, including its 'id' key.
   */
  protected array $transport = [];

  /**
   * Lazily instantiated transport plugin.
   */
  protected ?DefaultSingleLazyPluginCollection $transportCollection = NULL;

  /**
   * {@inheritdoc}
   */
  public function getTransport(): TransportInterface {
    $transport = $this->transportCollection()->get($this->getTransportId());
    assert($transport instanceof TransportInterface);
    return $transport;
  }

  /**
   * {@inheritdoc}
   */
  public function getTransportId(): string {
    return (string) ($this->transport['id'] ?? 'log');
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
    // and a changed transport would be silently ignored.
    if ($property_name === 'transport') {
      $this->transportCollection = NULL;
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginCollections(): array {
    return ['transport' => $this->transportCollection()];
  }

  /**
   * The lazily built transport plugin collection.
   */
  protected function transportCollection(): DefaultSingleLazyPluginCollection {
    if ($this->transportCollection === NULL) {
      $this->transportCollection = new DefaultSingleLazyPluginCollection(
        \Drupal::service('plugin.manager.klaxon_transport'),
        $this->getTransportId(),
        $this->transport,
      );
    }

    return $this->transportCollection;
  }

}
