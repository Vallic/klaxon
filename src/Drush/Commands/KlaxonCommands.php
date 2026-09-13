<?php

declare(strict_types=1);

namespace Drupal\klaxon\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\DelayableQueueInterface;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\klaxon\Alert\Deliverer;
use Drupal\klaxon\Alert\Dispatcher;
use Drupal\klaxon\Alert\StateStore;
use Drupal\klaxon\DeliveryException;
use Drupal\klaxon\Entity\AlertInterface;
use Drupal\klaxon\Entity\ChannelInterface;
use Drupal\klaxon\Message;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands for Klaxon.
 *
 * Klaxon runs on cron and needs no command to work. What these are for is the
 * question cron cannot answer: why did this alert not say anything. Running
 * one from a terminal and being told what it measured beats reading the log
 * and guessing.
 */
final class KlaxonCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Delivering through the queue, the way cron does.
   */
  private const DELIVER_QUEUE = 'queue';

  /**
   * Delivering inline, so the terminal can report what happened.
   */
  private const DELIVER_NOW = 'now';

  public function __construct(
    #[Autowire(service: 'entity_type.manager')]
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'klaxon.dispatcher')]
    private readonly Dispatcher $dispatcher,
    #[Autowire(service: 'klaxon.deliverer')]
    private readonly Deliverer $deliverer,
    #[Autowire(service: 'klaxon.state')]
    private readonly StateStore $stateStore,
    #[Autowire(service: 'queue')]
    private readonly QueueFactory $queueFactory,
    #[Autowire(service: 'plugin.manager.queue_worker')]
    private readonly QueueWorkerManagerInterface $queueWorkerManager,
    #[Autowire(service: 'date.formatter')]
    private readonly DateFormatterInterface $dateFormatter,
    #[Autowire(service: 'datetime.time')]
    private readonly TimeInterface $time,
  ) {
    parent::__construct();
  }

  /**
   * List the alerts, with what each one last did.
   */
  #[CLI\Command(name: 'klaxon:list', aliases: ['kx-list'])]
  #[CLI\FieldLabels(labels: [
    'id' => 'ID',
    'label' => 'Alert',
    'kind' => 'Kind',
    'enabled' => 'Enabled',
    'state' => 'State',
    'last_run' => 'Last checked',
    'last_fired' => 'Last fired',
    'fired' => 'Times fired',
    'channels' => 'Channels',
  ])]
  #[CLI\DefaultTableFields(fields: ['id', 'kind', 'enabled', 'state', 'last_run', 'last_fired', 'channels'])]
  #[CLI\Usage(name: 'drush klaxon:list', description: 'Every alert, and whether it is currently firing.')]
  public function list(): RowsOfFields {
    $rows = [];

    foreach ($this->alerts() as $id => $alert) {
      $state = $this->stateStore->get($id);

      $rows[$id] = [
        'id' => $id,
        'label' => (string) $alert->label(),
        'kind' => $alert->getTypeId(),
        'enabled' => $alert->status() ? 'yes' : 'no',
        'state' => $state->isFiring() ? 'FIRING' : 'quiet',
        'last_run' => $this->ago($state->lastRun),
        'last_fired' => $this->ago($state->lastFired),
        'fired' => $state->fireCount,
        'channels' => implode(', ', $alert->getChannelIds()) ?: '-',
      ];
    }

    $this->warnAboutBacklog();

    return new RowsOfFields($rows);
  }

  /**
   * Evaluate one alert now and say what it decided.
   */
  #[CLI\Command(name: 'klaxon:run', aliases: ['kx-run'])]
  #[CLI\Argument(name: 'alert', description: 'The alert ID.')]
  #[CLI\Option(name: 'deliver', description: 'Where the message goes: <info>now</info> to send it inline, <info>queue</info> to hand it to the delivery queue the way cron does.')]
  #[CLI\Usage(name: 'drush klaxon:run quiet_shop', description: 'Evaluate it and deliver immediately.')]
  #[CLI\Usage(name: 'drush klaxon:run quiet_shop --deliver=queue', description: 'Evaluate it and queue the result, exactly as cron would.')]
  public function run(string $alert, array $options = ['deliver' => self::DELIVER_NOW]): int {
    if (!$this->alert($alert) instanceof AlertInterface) {
      $this->logger()->error(dt('No alert with the ID @id.', ['@id' => $alert]));
      return self::EXIT_FAILURE;
    }

    $queued = $this->wantsQueue($options);

    $said = $queued
      ? $this->dispatcher->fire($alert)
      : $this->dispatcher->fireNow($alert);

    if (!$said) {
      // Not an error. Most alerts, most of the time, have nothing to say, and
      // a non-zero exit would make that look like a failure in a script.
      $this->io()->text(dt('@id had nothing to say.', ['@id' => $alert]));
      return self::EXIT_SUCCESS;
    }

    $this->io()->success($queued
      ? dt('@id fired. The message is queued.', ['@id' => $alert])
      : dt('@id fired and was delivered.', ['@id' => $alert]));

    if ($queued) {
      $this->warnAboutBacklog();
    }

    return self::EXIT_SUCCESS;
  }

  /**
   * Evaluate every alert that is due, the way cron does.
   */
  #[CLI\Command(name: 'klaxon:due', aliases: ['kx-due'])]
  #[CLI\Option(name: 'deliver', description: 'Where messages go: <info>queue</info> as cron does, or <info>now</info> to send them inline.')]
  #[CLI\Usage(name: 'drush klaxon:due', description: 'What a cron run would do, without running the rest of cron.')]
  public function due(array $options = ['deliver' => self::DELIVER_QUEUE]): int {
    if ($this->wantsQueue($options)) {
      $fired = $this->dispatcher->runDue();

      $this->io()->text($fired === 0
        ? dt('Nothing due had anything to say.')
        : dt('@count alerts fired and were queued.', ['@count' => $fired]));

      $this->warnAboutBacklog();

      return self::EXIT_SUCCESS;
    }

    // Inline: evaluate the same set, but deliver each straight away so the
    // terminal can report the outcome rather than the intent.
    $runner = $this->dispatcher;
    $fired = 0;

    foreach ($this->alerts() as $id => $alert) {
      if (!$alert->status()) {
        continue;
      }

      if ($runner->fireNow($id)) {
        $this->io()->text(dt(' - @id fired and was delivered.', ['@id' => $id]));
        $fired++;
      }
    }

    $this->io()->text(dt('@count alerts fired.', ['@count' => $fired]));

    return self::EXIT_SUCCESS;
  }

  /**
   * Work the delivery queue now, instead of waiting for cron.
   */
  #[CLI\Command(name: 'klaxon:deliver', aliases: ['kx-deliver'])]
  #[CLI\Option(name: 'limit', description: 'Stop after this many messages. 0 means no limit.')]
  #[CLI\Option(name: 'time', description: 'Stop after this many seconds. 0 means no limit.')]
  #[CLI\Usage(name: 'drush klaxon:deliver', description: 'Send whatever is waiting.')]
  #[CLI\Usage(name: 'drush klaxon:deliver --limit=1', description: 'Send one, to see whether delivery works at all.')]
  public function deliver(array $options = ['limit' => 0, 'time' => 30]): int {
    $queue = $this->queueFactory->get(Deliverer::QUEUE);
    $waiting = (int) $queue->numberOfItems();

    if ($waiting === 0) {
      $this->io()->text(dt('The delivery queue is empty.'));
      return self::EXIT_SUCCESS;
    }

    $worker = $this->queueWorkerManager->createInstance(Deliverer::QUEUE);
    $lease = (int) ($worker->getPluginDefinition()['cron']['time'] ?? 30);
    $limit = max(0, (int) $options['limit']);
    $seconds = max(0, (int) $options['time']);
    $deadline = $seconds === 0 ? PHP_INT_MAX : time() + $seconds;

    $sent = 0;
    $failed = 0;

    // Item handling matches core's own queue runner, so a message delivered
    // from here behaves exactly as it would have on cron: same retries, same
    // rate-limit handling, same lease.
    while (time() < $deadline && ($limit === 0 || $sent + $failed < $limit)) {
      $item = $queue->claimItem($lease);

      // Nothing claimable: either the queue is empty, or everything left in it
      // is leased to another process or held back for a later retry.
      if (!is_object($item)) {
        break;
      }

      try {
        $worker->processItem($item->data);
        $queue->deleteItem($item);
        $sent++;
      }
      catch (DelayedRequeueException $e) {
        if ($queue instanceof DelayableQueueInterface) {
          $queue->delayItem($item, $e->getDelay());
        }
        $failed++;
      }
      catch (RequeueException) {
        $queue->releaseItem($item);
        $failed++;
      }
      catch (SuspendQueueException $e) {
        $queue->releaseItem($item);
        $this->logger()->warning(dt('Delivery was suspended: @reason', ['@reason' => $e->getMessage()]));
        break;
      }
      catch (\Exception $e) {
        $queue->releaseItem($item);
        $this->logger()->error($e->getMessage());
        $failed++;
      }
    }

    $this->io()->success(dt('Delivered @sent of @waiting. @left still waiting.', [
      '@sent' => $sent,
      '@waiting' => $waiting,
      '@left' => (int) $queue->numberOfItems(),
    ]));

    if ($failed > 0) {
      $this->logger()->warning(dt('@count were put back for another attempt.', ['@count' => $failed]));
    }

    return self::EXIT_SUCCESS;
  }

  /**
   * Send a test message down one channel.
   */
  #[CLI\Command(name: 'klaxon:test', aliases: ['kx-test'])]
  #[CLI\Argument(name: 'channel', description: 'The channel ID.')]
  #[CLI\Usage(name: 'drush klaxon:test ops_slack', description: 'Prove the credentials work, without waiting for something to go wrong.')]
  public function test(string $channel): int {
    $entity = $this->entityTypeManager->getStorage('klaxon_channel')->load($channel);

    if (!$entity instanceof ChannelInterface) {
      $this->logger()->error(dt('No channel with the ID @id.', ['@id' => $channel]));
      return self::EXIT_FAILURE;
    }

    $message = new Message(
      'Klaxon test message',
      dt('Sent from the command line to check this channel works. Nothing is wrong.'),
      [
        'Channel' => (string) $entity->label(),
        'Sent' => $this->dateFormatter->format($this->time->getRequestTime(), 'custom', 'Y-m-d H:i T'),
      ],
      Message::SEVERITY_INFO,
    );

    try {
      // Straight at the transport, bypassing the queue: the whole point is to
      // find out now whether the credentials work.
      $this->deliverer->sendToChannel($entity, $message);
    }
    catch (DeliveryException $e) {
      $this->logger()->error(dt('@id refused it: @reason', ['@id' => $channel, '@reason' => $e->getMessage()]));
      return self::EXIT_FAILURE;
    }
    catch (\Throwable $e) {
      $this->logger()->error(dt('@id could not be reached: @reason', ['@id' => $channel, '@reason' => $e->getMessage()]));
      return self::EXIT_FAILURE;
    }

    $this->io()->success(dt('Sent to @id.', ['@id' => $channel]));

    return self::EXIT_SUCCESS;
  }

  /**
   * Whether the caller asked for the queue rather than inline delivery.
   */
  private function wantsQueue(array $options): bool {
    return strtolower((string) ($options['deliver'] ?? '')) !== self::DELIVER_NOW;
  }

  /**
   * Says so when messages are sitting in the queue, and what to do about it.
   *
   * A backlog here means cron is not draining the queue — which is the one
   * failure that makes every alert on the site go quiet while every alert on
   * the site still looks perfectly well configured.
   */
  private function warnAboutBacklog(): void {
    $waiting = (int) $this->queueFactory->get(Deliverer::QUEUE)->numberOfItems();

    if ($waiting === 0) {
      return;
    }

    // Through the logger, so it goes to stderr and leaves piped output alone.
    $this->logger()->notice(dt('@count messages are waiting in the delivery queue. Cron sends them; if cron is not running or the queue is stuck, send them now with: drush klaxon:deliver', [
      '@count' => $waiting,
    ]));
  }

  /**
   * Every alert, keyed by ID.
   *
   * @return \Drupal\klaxon\Entity\AlertInterface[]
   *   The alerts.
   */
  private function alerts(): array {
    $alerts = [];

    foreach ($this->entityTypeManager->getStorage('klaxon_alert')->loadMultiple() as $id => $alert) {
      if ($alert instanceof AlertInterface) {
        $alerts[(string) $id] = $alert;
      }
    }

    ksort($alerts);

    return $alerts;
  }

  /**
   * One alert, or NULL.
   */
  private function alert(string $id): ?AlertInterface {
    $alert = $this->entityTypeManager->getStorage('klaxon_alert')->load($id);

    return $alert instanceof AlertInterface ? $alert : NULL;
  }

  /**
   * A timestamp as how long ago it was.
   */
  private function ago(?int $timestamp): string {
    if ($timestamp === NULL || $timestamp === 0) {
      return 'never';
    }

    return dt('@interval ago', [
      '@interval' => $this->dateFormatter->formatInterval($this->time->getRequestTime() - $timestamp, 1),
    ]);
  }

}
