<?php

declare(strict_types=1);

namespace Drupal\klaxon_system\Plugin\Klaxon\AlertType;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Component\Utility\Html;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\klaxon\AlertType\ScheduledAlertBase;
use Drupal\klaxon\Attribute\AlertType;
use Drupal\klaxon\Reading;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Errors are piling up in the log.
 *
 * Nobody reads the log. That is not a criticism of anyone, it is what logs
 * are: a place to look once you already know something is wrong. This turns
 * it around — a rate worth caring about, said out loud.
 *
 * A count rather than each entry, because the interesting signal is almost
 * always volume. One PHP notice is Tuesday; four hundred of them in ten
 * minutes is a deployment that needs rolling back.
 */
#[AlertType(
  id: 'system_errors',
  label: new TranslatableMarkup('Errors in the log'),
  description: new TranslatableMarkup('Count what has been logged at a severity worth caring about, and fire when there is more of it than usual. Needs the Database Logging module.'),
  category: new TranslatableMarkup('System'),
)]
class LoggedErrors extends ScheduledAlertBase implements ContainerFactoryPluginInterface {

  /**
   * How many channels the breakdown names before it stops being readable.
   */
  protected const TOP_CHANNELS = 5;

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly Connection $database,
    protected readonly TimeInterface $time,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->setConfiguration($configuration);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('database'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'severity' => RfcLogLevel::ERROR,
      'channels' => [],
      'minutes' => 60,
      'interval' => 900,
      'operator' => '>',
      'value' => 10,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['what'] = [
      '#type' => 'details',
      '#title' => $this->t('What counts as an error'),
      '#open' => TRUE,
      '#weight' => -5,
    ];

    $form['what']['severity'] = [
      '#type' => 'select',
      '#title' => $this->t('Severity'),
      '#options' => [
        RfcLogLevel::CRITICAL => $this->t('Critical or worse'),
        RfcLogLevel::ERROR => $this->t('Error or worse'),
        RfcLogLevel::WARNING => $this->t('Warning or worse'),
      ],
      '#default_value' => $this->configuration['severity'] ?? RfcLogLevel::ERROR,
      '#description' => $this->t('Anything logged at this level or more serious is counted.'),
    ];

    $form['what']['channels'] = [
      '#type' => 'select',
      '#title' => $this->t('Channels'),
      '#multiple' => TRUE,
      '#options' => $this->channelOptions(),
      '#default_value' => (array) ($this->configuration['channels'] ?? []),
      '#description' => $this->t('Leave empty for every channel. Narrowing to one is how you say "errors from payments", which is worth hearing about at a much lower count than errors in general.'),
    ];

    $form['what']['minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Counted over the last'),
      '#field_suffix' => $this->t('minutes'),
      '#min' => 1,
      '#default_value' => $this->configuration['minutes'] ?? 60,
      '#required' => TRUE,
    ];

    $form['fire']['operator']['#title'] = $this->t('Fire when the number of entries');

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function formSections(): array {
    return ['schedule', 'what', 'fire'];
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): void {
    parent::setConfiguration($configuration);

    $this->configuration['channels'] = array_values(array_filter((array) ($this->configuration['channels'] ?? [])));
    $this->configuration['minutes'] = max(1, (int) ($this->configuration['minutes'] ?? 60));
    $this->configuration['severity'] = (int) ($this->configuration['severity'] ?? RfcLogLevel::ERROR);
  }

  /**
   * {@inheritdoc}
   */
  public function read(array $context = []): Reading {
    $since = $this->time->getRequestTime() - ($this->minutes() * 60);

    $count = (int) $this->baseQuery($since)
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($count === 0) {
      return Reading::scalar(0, ['Window' => $this->windowSummary()]);
    }

    return Reading::scalar($count, [
      'Window' => $this->windowSummary(),
      'Channels' => $this->breakdown($since),
      'Most recent' => $this->mostRecent($since),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): string {
    return (string) new TranslatableMarkup('@severity in @channels over @count minutes, @test', [
      '@severity' => $this->severityLabel(),
      '@channels' => ($channels = (array) ($this->configuration['channels'] ?? [])) === []
        ? (string) new TranslatableMarkup('any channel')
        : implode(', ', $channels),
      '@count' => $this->minutes(),
      '@test' => $this->thresholdSummary(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): array {
    return ['module' => ['dblog']];
  }

  /**
   * The window in minutes, never less than one.
   */
  protected function minutes(): int {
    return max(1, (int) ($this->configuration['minutes'] ?? 60));
  }

  /**
   * The query every read starts from.
   */
  protected function baseQuery(int $since) {
    $query = $this->database->select('watchdog', 'w')
      ->condition('w.timestamp', $since, '>=')
      // Severity counts up as things get less serious, so "error or worse"
      // is everything at or below the chosen number.
      ->condition('w.severity', (int) ($this->configuration['severity'] ?? RfcLogLevel::ERROR), '<=');

    $channels = array_filter((array) ($this->configuration['channels'] ?? []));

    if ($channels !== []) {
      $query->condition('w.type', array_values($channels), 'IN');
    }

    return $query;
  }

  /**
   * The busiest channels in the window, as one line.
   */
  protected function breakdown(int $since): string {
    $query = $this->baseQuery($since);
    $query->addField('w', 'type');
    $query->addExpression('COUNT(*)', 'total');
    $query->groupBy('w.type');
    $query->orderBy('total', 'DESC');
    $query->range(0, self::TOP_CHANNELS);

    $parts = [];
    foreach ($query->execute() as $row) {
      $parts[] = sprintf('%s (%d)', $row->type, $row->total);
    }

    return implode(', ', $parts);
  }

  /**
   * How much of the latest message is worth carrying into an alert.
   */
  protected const MESSAGE_LENGTH = 200;

  /**
   * The latest entry in the window, as something a human can read.
   */
  protected function mostRecent(int $since): string {
    $query = $this->baseQuery($since);
    $query->addField('w', 'message');
    $query->addField('w', 'variables');
    $query->orderBy('w.wid', 'DESC');
    $query->range(0, 1);

    $row = $query->execute()->fetchObject();

    if ($row === FALSE || $row === NULL) {
      return '';
    }

    // Watchdog stores the placeholders separately, and they are whatever the
    // logging call passed. Objects are refused on the way back out.
    $variables = @unserialize((string) $row->variables, ['allowed_classes' => FALSE]);

    $message = is_array($variables)
      ? (string) new FormattableMarkup((string) $row->message, $variables)
      : (string) $row->message;

    // A %placeholder renders as markup, which is right for the log page and
    // wrong for everywhere an alert goes. Most of these end up in plain text.
    return $this->trim(Html::decodeEntities(strip_tags($message)), self::MESSAGE_LENGTH);
  }

  /**
   * Cuts a string to a length worth reading.
   */
  protected function trim(string $text, int $limit): string {
    if (mb_strlen($text) <= $limit) {
      return $text;
    }

    return mb_substr($text, 0, max(1, $limit - 1)) . '…';
  }

  /**
   * The log channels that have actually been used.
   */
  protected function channelOptions(): array {
    $options = [];

    if (!$this->database->schema()->tableExists('watchdog')) {
      return $options;
    }

    $types = $this->database->select('watchdog', 'w')
      ->distinct()
      ->fields('w', ['type'])
      ->orderBy('w.type')
      ->range(0, 128)
      ->execute()
      ->fetchCol();

    foreach ($types as $type) {
      $options[(string) $type] = (string) $type;
    }

    return $options;
  }

  /**
   * The chosen severity in words.
   */
  protected function severityLabel(): string {
    return (string) match ((int) ($this->configuration['severity'] ?? RfcLogLevel::ERROR)) {
      RfcLogLevel::CRITICAL => new TranslatableMarkup('Critical or worse'),
      RfcLogLevel::WARNING => new TranslatableMarkup('Warning or worse'),
      default => new TranslatableMarkup('Error or worse'),
    };
  }

  /**
   * The window in words.
   */
  protected function windowSummary(): string {
    return (string) new TranslatableMarkup('last @count minutes', ['@count' => $this->minutes()]);
  }

}
