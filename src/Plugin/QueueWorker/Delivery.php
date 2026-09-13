<?php

declare(strict_types=1);

namespace Drupal\klaxon\Plugin\QueueWorker;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\DelayableQueueInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\klaxon\Alert\Deliverer;
use Drupal\klaxon\DeliveryException;
use Drupal\klaxon\Entity\ChannelInterface;
use Drupal\klaxon\Message;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Delivers one queued message to one channel.
 *
 * Retries are done by putting a fresh job on the queue with the attempt count
 * raised, rather than by delaying the current one, so the count travels with
 * the job and survives any queue backend.
 *
 * The replacement is then held back for a growing delay. Without that it would
 * be claimed again immediately, because cron drains a queue in a single pass:
 * three attempts would happen back to back in the same second and none of them
 * would give the far end time to recover.
 */
#[QueueWorker(
  id: Deliverer::QUEUE,
  title: new TranslatableMarkup('Klaxon delivery'),
  cron: ['time' => 30],
)]
class Delivery extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly QueueFactory $queueFactory,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly Deliverer $deliverer,
    protected readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
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
      $container->get('queue'),
      $container->get('config.factory'),
      $container->get('klaxon.deliverer'),
      $container->get('logger.channel.klaxon'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $message = $this->message($data);

    if ($message === NULL) {
      $this->logger->error('Discarding a malformed Klaxon delivery job.');
      return;
    }

    $channel = $this->entityTypeManager->getStorage('klaxon_channel')->load($data['channel_id'] ?? '');

    if (!$channel instanceof ChannelInterface || !$channel->status()) {
      // The channel was deleted or switched off while the job waited. That is
      // an intentional act, so the job goes quietly rather than escalating.
      return;
    }

    try {
      $this->deliverer->sendToChannel($channel, $message);
    }
    catch (DeliveryException $e) {
      $this->handleFailure($data, $channel, $e);
    }
    catch (\Throwable $e) {
      $this->handleFailure($data, $channel, new DeliveryException($e->getMessage(), FALSE, NULL, $e));
    }
  }

  /**
   * The message a job is carrying, however the queue gave it back.
   *
   * Jobs are written as plain data, but a backend that does preserve objects
   * will hand back anything queued by an earlier version of this module as a
   * Message, and those jobs are still worth delivering.
   */
  protected function message($data): ?Message {
    if (!is_array($data)) {
      return NULL;
    }

    $message = $data['message'] ?? NULL;

    if ($message instanceof Message) {
      return $message;
    }

    if (!is_array($message) || (string) ($message['subject'] ?? '') === '') {
      return NULL;
    }

    return Message::fromArray($message);
  }

  /**
   * Decides between waiting, retrying and giving up.
   */
  protected function handleFailure(array $data, ChannelInterface $channel, DeliveryException $e): void {
    $alert_id = (string) ($data['alert_id'] ?? '');
    $attempt = (int) ($data['attempt'] ?? 1);
    $max = max(1, (int) $this->configFactory->get('klaxon.settings')->get('max_attempts'));

    if ($e->isPermanent()) {
      $this->giveUp($data, $channel, $e, $this->t('the failure is permanent'));
      return;
    }

    if (($seconds = $e->getRetryAfter()) !== NULL) {
      $this->logger->notice('Channel @channel asked Klaxon to wait @seconds seconds. Pausing deliveries until the next run.', [
        '@channel' => $channel->id(),
        '@seconds' => $seconds,
      ]);

      // Suspending is enough to put this job back: the caller releases the
      // claimed item and moves on. Creating one here as well would duplicate
      // the message. Being asked to slow down is nobody's fault, so the
      // attempt count is deliberately left alone.
      throw new SuspendQueueException($e->getMessage());
    }

    if ($attempt >= $max) {
      $this->giveUp($data, $channel, $e, $this->t('@count attempts failed', ['@count' => $attempt]));
      return;
    }

    $data['attempt'] = $attempt + 1;
    $delay = $this->retryDelay($attempt);
    $this->requeue($data, $delay);

    $this->logger->warning('Alert @alert failed to reach channel @channel on attempt @attempt: @message. Trying again in @delay seconds.', [
      '@alert' => $alert_id,
      '@channel' => $channel->id(),
      '@attempt' => $attempt,
      '@message' => $e->getMessage(),
      '@delay' => $delay,
    ]);
  }

  /**
   * Puts a job back, held for the given number of seconds.
   *
   * A backend that cannot delay simply retries on the next pass, which is a
   * worse pace but not a broken one.
   */
  protected function requeue(array $data, int $delay): void {
    $queue = $this->queueFactory->get(Deliverer::QUEUE);
    $item_id = $queue->createItem($data);

    if ($delay > 0 && $item_id && $queue instanceof DelayableQueueInterface) {
      $item = new \stdClass();
      $item->item_id = $item_id;
      $queue->delayItem($item, $delay);
    }
  }

  /**
   * How long to wait before the next attempt, doubling each time.
   */
  protected function retryDelay(int $attempt): int {
    $base = max(1, (int) $this->configFactory->get('klaxon.settings')->get('retry_delay'));

    // Capped so a long-dead channel does not push its retry beyond the point
    // anyone still cares about the alert.
    return (int) min($base * (2 ** max(0, $attempt - 1)), 3600);
  }

  /**
   * Logs a delivery as lost, and tells the fallback channel if there is one.
   */
  protected function giveUp(array $data, ChannelInterface $channel, DeliveryException $e, $reason): void {
    $alert_id = (string) ($data['alert_id'] ?? '');

    $this->logger->critical('Alert @alert never reached channel @channel: @message (@reason).', [
      '@alert' => $alert_id,
      '@channel' => $channel->id(),
      '@message' => $e->getMessage(),
      '@reason' => $reason,
    ]);

    $this->escalate($alert_id, $channel, $e);
  }

  /**
   * Tells the fallback channel that alerting itself is failing.
   *
   * Sent directly rather than queued: the queue is what just failed, and a
   * loop between a broken channel and its own failure notice helps nobody.
   */
  protected function escalate(string $alert_id, ChannelInterface $failed, DeliveryException $e): void {
    $fallback_id = (string) $this->configFactory->get('klaxon.settings')->get('fallback_channel');

    if ($fallback_id === '' || $fallback_id === $failed->id()) {
      return;
    }

    $fallback = $this->entityTypeManager->getStorage('klaxon_channel')->load($fallback_id);

    if (!$fallback instanceof ChannelInterface || !$fallback->status()) {
      return;
    }

    $notice = new Message(
      subject: (string) $this->t('Klaxon could not deliver an alert'),
      body: (string) $this->t('Alert @alert never reached channel @channel. The last error was: @message', [
        '@alert' => $alert_id,
        '@channel' => $failed->label(),
        '@message' => $e->getMessage(),
      ]),
      facts: [
        'Alert' => $alert_id,
        'Channel' => (string) $failed->label(),
      ],
      severity: Message::SEVERITY_CRITICAL,
    );

    try {
      $fallback->getTransport()->send($notice);
    }
    catch (\Throwable $inner) {
      $this->logger->critical('The Klaxon fallback channel @fallback also failed: @message', [
        '@fallback' => $fallback_id,
        '@message' => $inner->getMessage(),
      ]);
    }
  }

}
