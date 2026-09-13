<?php

declare(strict_types=1);

namespace Drupal\klaxon\Transport;

use Drupal\klaxon\Message;

use Drupal\klaxon\KlaxonPluginInterface;

/**
 * Delivers a rendered message somewhere.
 *
 * The plugin carries the connection settings of the channel that holds it, so
 * one Slack workspace is configured once and reused by every alert pointing at
 * that channel.
 */
interface TransportInterface extends KlaxonPluginInterface {

  /**
   * Delivers the message.
   *
   * @throws \Drupal\klaxon\DeliveryException
   *   On any failure the caller should retry.
   */
  public function send(Message $message): void;

}
