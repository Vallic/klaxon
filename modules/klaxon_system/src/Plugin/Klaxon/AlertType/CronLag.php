<?php

declare(strict_types=1);

namespace Drupal\klaxon_system\Plugin\Klaxon\AlertType;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\klaxon\AlertType\ScheduledAlertBase;
use Drupal\klaxon\Attribute\AlertType;
use Drupal\klaxon\Reading;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The last completed cron run is further back than it should be.
 *
 * Read the limitation before relying on this. Klaxon evaluates scheduled
 * alerts on cron, so an alert about cron can only speak while cron is running.
 * If cron dies completely, this says nothing — nothing evaluated by cron
 * could. Catching that needs something outside the site, watching the site.
 *
 * What it does catch is the failure that actually happens more often and is
 * far harder to notice: cron that runs but never finishes. Drupal stamps
 * `system.cron_last` at the end of a successful run, so a hook that fatals
 * halfway leaves that timestamp standing still while cron appears, from the
 * outside, to be firing perfectly happily. It also catches cron running far
 * less often than whoever set it up believes.
 */
#[AlertType(
  id: 'system_cron',
  label: new TranslatableMarkup('Cron is falling behind'),
  description: new TranslatableMarkup('Fire when the last completed cron run is older than it should be. Catches cron that runs but never finishes, and cron running less often than you think — but not cron that has stopped entirely, which nothing running on cron can catch.'),
  category: new TranslatableMarkup('System'),
)]
class CronLag extends ScheduledAlertBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly StateInterface $state,
    protected readonly TimeInterface $time,
    protected readonly DateFormatterInterface $dateFormatter,
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
      $container->get('state'),
      $container->get('datetime.time'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'hours' => 6,
      'interval' => 3600,
      // Not configurable: the threshold is the number of hours, in seconds.
      'operator' => '>',
      'value' => 6 * 3600,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    // There is no threshold to pick beyond the number of hours.
    unset($form['fire']);

    $form['lag'] = [
      '#type' => 'details',
      '#title' => $this->t('How far behind is too far'),
      '#open' => TRUE,
      '#weight' => -5,
    ];

    $form['lag']['hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Complain when the last finished run is older than'),
      '#field_suffix' => $this->t('hours'),
      '#min' => 1,
      '#default_value' => $this->configuration['hours'] ?? 6,
      '#required' => TRUE,
      '#description' => $this->t('Set it comfortably above how often cron is supposed to run. On hourly cron, six hours means five runs were missed or died before finishing.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function formSections(): array {
    return ['schedule', 'lag'];
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): void {
    parent::setConfiguration($configuration);

    // Derived here rather than on form submit, because configuration arrives
    // three ways — a form, a config import, and code creating the alert — and
    // only one of them goes through a form. The derived values are still
    // stored, so an exported alert says what it actually tests.
    $hours = max(1, (int) ($this->configuration['hours'] ?? 6));

    $this->configuration['hours'] = $hours;
    $this->configuration['operator'] = '>';
    $this->configuration['value'] = $hours * 3600;
  }

  /**
   * {@inheritdoc}
   */
  public function read(array $context = []): Reading {
    $last = (int) $this->state->get('system.cron_last', 0);
    $now = $this->time->getRequestTime();

    if ($last === 0) {
      return Reading::scalar($now, ['Last finished run' => 'never']);
    }

    return Reading::scalar($now - $last, [
      // An explicit format rather than a named one. Named formats are config
      // entities that a minimal site may not have, and an ops message wants
      // an unambiguous timestamp more than a localised one.
      'Last finished run' => $this->dateFormatter->format($last, 'custom', 'Y-m-d H:i T'),
      'Behind by' => $this->dateFormatter->formatInterval($now - $last, 2),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): string {
    return (string) new TranslatableMarkup('Cron has not finished in @count hours', [
      '@count' => (int) ($this->configuration['hours'] ?? 6),
    ]);
  }

}
