<?php

declare(strict_types=1);

namespace Drupal\klaxon\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\klaxon\Transport\TransportInterface;

/**
 * A configured place alerts can be delivered to.
 */
interface ChannelInterface extends ConfigEntityInterface {

  /**
   * The configured transport plugin.
   */
  public function getTransport(): TransportInterface;

  /**
   * The transport plugin id, without instantiating it.
   */
  public function getTransportId(): string;

}
