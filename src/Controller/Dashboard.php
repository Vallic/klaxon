<?php

declare(strict_types=1);

namespace Drupal\klaxon\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Url;
use Drupal\klaxon\Alert\Deliverer;
use Drupal\klaxon\Alert\StateStore;
use Drupal\klaxon\AlertType\EntityEventAlertInterface;
use Drupal\klaxon\AlertType\ScheduledAlertInterface;
use Drupal\klaxon\Entity\AlertInterface;
use Drupal\klaxon\Entity\ChannelInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows what is firing, what gets told, and what is about to say nothing.
 *
 * The two lists answer "what alerts exist" and "what channels exist". The
 * question neither answers is the one that matters at nine in the morning:
 * is anything wrong right now, and if something goes wrong later, will
 * anybody actually hear about it.
 */
class Dashboard extends ControllerBase {

  public function __construct(
    protected readonly StateStore $stateStore,
    protected readonly QueueFactory $queueFactory,
    protected readonly DateFormatterInterface $dateFormatter,
    protected readonly TimeInterface $time,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('klaxon.state'),
      $container->get('queue'),
      $container->get('date.formatter'),
      $container->get('datetime.time'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Builds the dashboard.
   */
  public function build(): array {
    $alerts = $this->entityTypeManager->getStorage('klaxon_alert')->loadMultiple();
    $channels = $this->entityTypeManager->getStorage('klaxon_channel')->loadMultiple();
    $states = [];

    foreach ($alerts as $id => $alert) {
      $states[$id] = $this->stateStore->get((string) $id);
    }

    return [
      '#attached' => ['library' => ['klaxon/dashboard']],
      '#cache' => [
        'tags' => [
          'config:klaxon_alert_list',
          'config:klaxon_channel_list',
        ],
        // The state of an alert changes on cron, not on a config save.
        'max-age' => 0,
      ],
      'summary' => $this->summary($alerts, $channels, $states),
      'warnings' => $this->warnings($alerts, $channels),
      'firing' => $this->firing($alerts, $states),
      'routing' => $this->routing($alerts, $channels),
      'state' => $this->stateTable($alerts, $states),
    ];
  }

  /**
   * The counts, across the top.
   */
  protected function summary(array $alerts, array $channels, array $states): array {
    $enabled = array_filter($alerts, static fn(AlertInterface $alert): bool => $alert->status());
    $firing = array_filter($states, static fn($state): bool => $state->isFiring());

    $items = [
      ['label' => $this->t('Enabled alerts'), 'value' => count($enabled)],
      ['label' => $this->t('Firing now'), 'value' => count($firing)],
      [
        'label' => $this->t('Channels'),
        'value' => count(array_filter($channels, static fn(ChannelInterface $channel): bool => $channel->status())),
      ],
      [
        'label' => $this->t('Waiting to send'),
        'value' => (int) $this->queueFactory->get(Deliverer::QUEUE)->numberOfItems(),
      ],
    ];

    return [
      '#theme' => 'item_list',
      '#attributes' => ['class' => ['klaxon-summary']],
      '#items' => array_map(
        static fn(array $item): array => [
          '#type' => 'inline_template',
          '#template' => '<span class="klaxon-summary__value">{{ value }}</span><span class="klaxon-summary__label">{{ label }}</span>',
          '#context' => $item,
        ],
        $items,
      ),
    ];
  }

  /**
   * Everything that means nobody will hear about something.
   */
  protected function warnings(array $alerts, array $channels): array {
    $messages = [];
    $waiting = (int) $this->queueFactory->get(Deliverer::QUEUE)->numberOfItems();

    // First, because it silences every alert at once while each of them
    // still looks perfectly well configured.
    if ($waiting > 0) {
      $messages[] = $this->t('@count messages are waiting in the delivery queue. Cron sends them; if they are still here next time you look, nothing is draining it and no alert is reaching anyone. Run <code>drush klaxon:deliver</code> to send them now.', [
        '@count' => $waiting,
      ]);
    }

    foreach ($alerts as $alert) {
      assert($alert instanceof AlertInterface);

      if (!$alert->status()) {
        continue;
      }

      $ids = $alert->getChannelIds();

      if ($ids === []) {
        $messages[] = $this->t('%alert has no channel, so it runs, records its state, and says nothing to anybody.', ['%alert' => $alert->label()]);
        continue;
      }

      foreach ($ids as $id) {
        if (!isset($channels[$id])) {
          $messages[] = $this->t('%alert delivers to a channel that no longer exists.', ['%alert' => $alert->label()]);
        }
        elseif (!$channels[$id]->status()) {
          $messages[] = $this->t('%alert delivers to %channel, which is switched off.', [
            '%alert' => $alert->label(),
            '%channel' => $channels[$id]->label(),
          ]);
        }
      }
    }

    if ($messages === []) {
      return [];
    }

    return [
      '#theme' => 'item_list',
      '#title' => $this->t('Worth fixing'),
      '#items' => $messages,
      '#attributes' => ['class' => ['klaxon-warnings']],
    ];
  }

  /**
   * What is wrong right now, at the top where it belongs.
   */
  protected function firing(array $alerts, array $states): array {
    $rows = [];

    foreach ($alerts as $id => $alert) {
      assert($alert instanceof AlertInterface);

      if (!isset($states[$id]) || !$states[$id]->isFiring()) {
        continue;
      }

      $rows[] = [
        'label' => $alert->label(),
        'summary' => $alert->getType()->summary(),
        'severity' => $alert->getSeverity(),
        'since' => $this->ago($states[$id]->lastFired),
        'url' => $alert->toUrl('edit-form')->toString(),
      ];
    }

    if ($rows === []) {
      return [];
    }

    return ['#theme' => 'klaxon_firing', '#rows' => $rows];
  }

  /**
   * One card per channel, listing what it will be told about.
   */
  protected function routing(array $alerts, array $channels): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['klaxon-channels']],
    ];

    if ($channels === []) {
      $build['none'] = [
        '#markup' => '<p>' . $this->t('No channels yet. Until there is one, every alert runs and says nothing.') . '</p>',
      ];

      return $build;
    }

    foreach ($channels as $id => $channel) {
      assert($channel instanceof ChannelInterface);

      $feeding = [];

      foreach ($alerts as $alert) {
        assert($alert instanceof AlertInterface);

        if (in_array($id, $alert->getChannelIds(), TRUE)) {
          $feeding[] = [
            'label' => $alert->label(),
            'kind' => $this->kind($alert),
            'severity' => $alert->getSeverity(),
            'enabled' => $alert->status(),
            'url' => $alert->toUrl('edit-form')->toString(),
          ];
        }
      }

      $build[$id] = [
        '#theme' => 'klaxon_channel_card',
        '#label' => $channel->label(),
        '#transport' => $channel->getTransport()->summary(),
        '#enabled' => $channel->status(),
        '#edit_url' => $channel->toUrl('edit-form')->toString(),
        '#alerts' => $feeding,
      ];
    }

    // Alerts pointing nowhere are the reason this page exists, so they get a
    // card of their own rather than being left out of the picture.
    $orphans = [];

    foreach ($alerts as $alert) {
      if ($alert->getChannelIds() === []) {
        $orphans[] = [
          'label' => $alert->label(),
          'kind' => $this->kind($alert),
          'severity' => $alert->getSeverity(),
          'enabled' => $alert->status(),
          'url' => $alert->toUrl('edit-form')->toString(),
        ];
      }
    }

    if ($orphans !== []) {
      $build['__nowhere'] = [
        '#theme' => 'klaxon_channel_card',
        '#label' => $this->t('Nowhere'),
        '#transport' => $this->t('These alerts have no channel'),
        '#enabled' => FALSE,
        '#edit_url' => '',
        '#alerts' => $orphans,
      ];
    }

    return $build;
  }

  /**
   * Every alert, and what it last did.
   *
   * Not state(): ControllerBase already has one of those, and it returns the
   * key-value store.
   */
  protected function stateTable(array $alerts, array $states): array {
    if ($alerts === []) {
      return [
        '#markup' => '<p>' . $this->t('No alerts yet. An alert is one thing worth knowing about, and where to say it.') . '</p>',
      ];
    }

    $rows = [];

    foreach ($alerts as $id => $alert) {
      assert($alert instanceof AlertInterface);
      $state = $states[$id];

      $rows[] = [
        'data' => [
          ['data' => ['#type' => 'link', '#title' => $alert->label(), '#url' => $alert->toUrl('edit-form')]],
          $this->kind($alert),
          [
            'data' => $state->isFiring() ? $this->t('Firing') : $this->t('Quiet'),
            'class' => [$state->isFiring() ? 'klaxon-state--firing' : 'klaxon-state--quiet'],
          ],
          $this->ago($state->lastRun),
          $this->ago($state->lastFired),
          $state->fireCount,
          [
            'data' => [
              '#type' => 'link',
              '#title' => $this->t('Run now'),
              '#url' => Url::fromRoute('klaxon.alert_run', ['klaxon_alert' => $id]),
            ],
          ],
        ],
        'class' => $alert->status() ? [] : ['klaxon-row--off'],
      ];
    }

    return [
      '#type' => 'table',
      '#caption' => $this->t('Every alert, and what it last did'),
      '#header' => [
        $this->t('Alert'),
        $this->t('Kind'),
        $this->t('State'),
        $this->t('Last checked'),
        $this->t('Last fired'),
        $this->t('Times'),
        '',
      ],
      '#rows' => $rows,
      '#attributes' => ['class' => ['klaxon-state-table']],
    ];
  }

  /**
   * What evaluates this alert, in one word.
   */
  protected function kind(AlertInterface $alert): string {
    $type = $alert->getType();

    if ($type instanceof ScheduledAlertInterface) {
      return (string) $this->t('On a schedule');
    }

    if ($type instanceof EntityEventAlertInterface) {
      return (string) $this->t('On a change');
    }

    return (string) $this->t('From code');
  }

  /**
   * A timestamp as how long ago it was.
   */
  protected function ago(?int $timestamp): string {
    if ($timestamp === NULL || $timestamp === 0) {
      return (string) $this->t('never');
    }

    return (string) $this->t('@interval ago', [
      '@interval' => $this->dateFormatter->formatInterval($this->time->getRequestTime() - $timestamp, 1),
    ]);
  }

}
