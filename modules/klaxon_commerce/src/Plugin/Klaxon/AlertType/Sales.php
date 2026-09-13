<?php

declare(strict_types=1);

namespace Drupal\klaxon_commerce\Plugin\Klaxon\AlertType;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\klaxon\Attribute\AlertType;
use Drupal\klaxon\Reading;
use Drupal\klaxon_commerce\OrderAlertBase;
use Drupal\state_machine\WorkflowManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * What the shop took over a period.
 *
 * The daily digest first, the target second. Left alone it reports yesterday's
 * takings every morning; change the test to "is below" and it becomes the
 * alert that says the day is going badly while there is still time to care.
 */
#[AlertType(
  id: 'commerce_sales',
  label: new TranslatableMarkup('Sales total'),
  description: new TranslatableMarkup('Add up what the shop took over a period, and say so — every morning, or only when the number is disappointing.'),
  category: new TranslatableMarkup('Commerce'),
)]
class Sales extends OrderAlertBase {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    TimeInterface $time,
    EntityTypeBundleInfoInterface $bundle_info,
    WorkflowManagerInterface $workflow_manager,
    EntityFieldManagerInterface $entity_field_manager,
    protected readonly CurrencyFormatterInterface $currencyFormatter,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $time, $bundle_info, $workflow_manager, $entity_field_manager);
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
      $container->get('datetime.time'),
      $container->get('entity_type.bundle.info'),
      $container->get('plugin.manager.workflow'),
      $container->get('entity_field.manager'),
      $container->get('commerce_price.currency_formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'aggregate' => self::SUM,
      'sum_field' => 'total_price.number',
      'date_field' => 'placed',
      'date_storage' => 'timestamp',
      'window_from' => '-24 hours',
      'window_to' => 'now',
      // Seven in the morning, about yesterday. The alert people actually ask
      // for, and the reason the cron expression exists at all.
      'cron' => '0 7 * * *',
      'interval' => 86400,
      // Always has something to say: this is a digest until someone changes it.
      'operator' => '>=',
      'value' => 0,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['period'] = [
      '#type' => 'details',
      '#title' => $this->t('Which period'),
      '#open' => TRUE,
      '#weight' => -5,
    ];

    $form['period']['window_from'] = [
      '#type' => 'textfield',
      '#title' => $this->t('From'),
      '#default_value' => $this->configuration['window_from'] ?? '-24 hours',
      '#description' => $this->t('Relative to the moment it runs. %day is yesterday, %week is the last seven days.', [
        '%day' => '-24 hours',
        '%week' => '-7 days',
      ]),
      '#required' => TRUE,
    ];

    $form['period']['window_to'] = [
      '#type' => 'textfield',
      '#title' => $this->t('To'),
      '#default_value' => $this->configuration['window_to'] ?? 'now',
      '#required' => TRUE,
    ];

    $form['fire']['#title'] = $this->t('When to say it');
    $form['fire']['operator']['#title'] = $this->t('Report the total when it');
    $form['fire']['operator']['#description'] = $this->t('Leave it at "is at least 0" for a plain daily digest. Set "is below" and a target to hear only about the bad days.');

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::submitConfigurationForm($form, $form_state);

    $period = (array) $form_state->getValue('period');

    $this->setConfiguration([
      'window_from' => (string) ($period['window_from'] ?? '-24 hours'),
      'window_to' => (string) ($period['window_to'] ?? 'now'),
    ] + $this->getConfiguration());
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::validateConfigurationForm($form, $form_state);

    $orders = (array) $form_state->getValue('orders');

    if ((string) ($orders['currency'] ?? '') === '') {
      $form_state->setError(
        $form['orders']['currency'],
        $this->t('Pick a currency. Adding up totals in different currencies produces a number that means nothing.'),
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function read(array $context = []): Reading {
    $reading = parent::read($context);
    $currency = (string) ($this->configuration['currency'] ?? '');

    if ($currency === '') {
      return $reading;
    }

    // The raw number goes to the threshold; the formatted one goes in the
    // message, because "12480.5" and "$12,480.50" are not the same sentence.
    return new Reading(
      $reading->value,
      $reading->rows,
      ['Total' => $this->currencyFormatter->format((string) $reading->measure(), $currency)] + $reading->context,
      $reading->cacheability,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): string {
    return (string) new TranslatableMarkup('Takings from @types @window, @test', [
      '@types' => $this->orderTypeSummary(),
      '@window' => $this->windowSummary(),
      '@test' => $this->thresholdSummary(),
    ]);
  }

}
