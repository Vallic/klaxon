<?php

declare(strict_types=1);

namespace Drupal\klaxon\Alert;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\klaxon\Entity\AlertInterface;
use Drupal\klaxon\Entity\ChannelInterface;
use Drupal\klaxon\Message;
use Psr\Log\LoggerInterface;

/**
 * Gets a message to an alert's channels.
 *
 * Delivery is queued by default. Cron and entity saves must not wait on a chat
 * API, and a customer's order must not fail to save because Slack is having a
 * bad afternoon. The queue also gives retries for free.
 *
 * Sending straight away stays available for the Run now button, where an editor
 * is watching and wants to know immediately whether the alert works.
 */
class Deliverer {

  /**
   * The queue holding one job per channel per fired alert.
   */
  public const QUEUE = 'klaxon_delivery';

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly QueueFactory $queueFactory,
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * Queues the message for every enabled channel on the alert.
   *
   * @param \Drupal\klaxon\Entity\AlertInterface $alert
   *   The alert that fired.
   * @param \Drupal\klaxon\Message $message
   *   The rendered message.
   *
   * @return int
   *   How many deliveries were queued.
   */
  public function enqueue(AlertInterface $alert, Message $message): int {
    $channels = $this->channels($alert);

    if ($channels === []) {
      return 0;
    }

    $queue = $this->queueFactory->get(self::QUEUE);
    $queued = 0;

    foreach ($channels as $channel) {
      // Plain data, not the object. Not every queue backend keeps objects.
      $queue->createItem([
        'alert_id' => (string) $alert->id(),
        'channel_id' => (string) $channel->id(),
        'message' => $message->toArray(),
        'attempt' => 1,
      ]);
      $queued++;
    }

    return $queued;
  }

  /**
   * Delivers the message now, without the queue.
   *
   * @param \Drupal\klaxon\Entity\AlertInterface $alert
   *   The alert that fired.
   * @param \Drupal\klaxon\Message $message
   *   The rendered message.
   *
   * @return int
   *   How many channels accepted the message.
   */
  public function send(AlertInterface $alert, Message $message): int {
    $delivered = 0;

    foreach ($this->channels($alert) as $channel) {
      try {
        $channel->getTransport()->send($message);
        $delivered++;
      }
      catch (\Throwable $e) {
        $this->logger->error('Alert @alert could not be delivered to channel @channel: @message', [
          '@alert' => $alert->id(),
          '@channel' => $channel->id(),
          '@message' => $e->getMessage(),
        ]);
      }
    }

    return $delivered;
  }

  /**
   * Hands one message to one channel, letting failures out.
   *
   * The queue worker calls this, because it needs to see the exception to
   * decide between retrying, waiting and giving up.
   *
   * @throws \Drupal\klaxon\DeliveryException
   */
  public function sendToChannel(ChannelInterface $channel, Message $message): void {
    $channel->getTransport()->send($message);
  }

  /**
   * The enabled channels an alert delivers to.
   *
   * @return \Drupal\klaxon\Entity\ChannelInterface[]
   *   The channels that are switched on, in configured order.
   */
  protected function channels(AlertInterface $alert): array {
    $ids = $alert->getChannelIds();

    if ($ids === []) {
      $this->logger->warning('Alert @id fired but has no channels configured.', ['@id' => $alert->id()]);
      return [];
    }

    $channels = [];
    foreach ($this->entityTypeManager->getStorage('klaxon_channel')->loadMultiple($ids) as $channel) {
      if ($channel instanceof ChannelInterface && $channel->status()) {
        $channels[] = $channel;
      }
    }

    return $channels;
  }

}
