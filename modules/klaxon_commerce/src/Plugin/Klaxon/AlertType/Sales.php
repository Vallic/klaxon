<?php

declare(strict_types=1);

namespace Drupal\klaxon_commerce\Plugin\Klaxon\AlertType;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\commerce_price\Entity\CurrencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
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
  public function read(array $context = []): Reading {
    $currency = (string) ($this->configuration['currency'] ?? '');

    // No currency picked means every currency, reported side by side. Adding
    // them together would still be meaningless, so they are not added: the
    // shop gets one line per currency and the threshold is tested against
    // the largest of them, so "a day under 1000" fires when any one currency
    // has a day under 1000 rather than never.
    if ($currency === '') {
      return $this->readByCurrency($context);
    }

    $reading = parent::read($context);

    // The raw number goes to the threshold; the formatted one goes in the
    // message, because "12480.5" and "$12,480.50" are not the same sentence.
    return new Reading(
      $reading->value,
      $reading->rows,
      ['Total' => $this->money((string) $reading->measure(), $currency)] + $reading->context,
      $reading->cacheability,
    );
  }

  /**
   * A total written the way the currency is written.
   *
   * The formatter trims a whole number to no decimals on its own, so a day
   * taking exactly 400 reads "€ 400" rather than "€ 400.00". Asking for the
   * currency's own fraction digits fixes that without hardcoding two of
   * them: yen has none, and "¥ 1,234.00" would be wrong in the other
   * direction.
   */
  protected function money(string $amount, string $code): string {
    $currency = $this->entityTypeManager->getStorage('commerce_currency')->load($code);
    $options = [];

    if ($currency instanceof CurrencyInterface) {
      $options['minimum_fraction_digits'] = $currency->getFractionDigits();
    }

    return $this->currencyFormatter->format($amount, $code, $options);
  }

  /**
   * Takings per currency, for a shop that sells in more than one.
   *
   * One aggregate query grouped by currency rather than one query per
   * currency, so a shop with twenty of them still costs one round trip.
   */
  protected function readByCurrency(array $context): Reading {
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheTags($this->entityTypeManager->getDefinition('commerce_order')->getListCacheTags());

    $result = $this->query('commerce_order', TRUE)
      ->aggregate('total_price.number', 'SUM')
      ->groupBy('total_price.currency_code')
      ->execute();

    $rows = [];
    $highest = 0.0;

    foreach ((array) $result as $row) {
      if (!is_array($row)) {
        continue;
      }

      // The result keys are derived from the field and function and differ
      // per field type, so they are found rather than guessed.
      $code = '';
      $total = 0.0;

      foreach ($row as $key => $value) {
        if (str_contains((string) $key, 'currency_code')) {
          $code = (string) $value;
        }
        elseif (str_contains((string) $key, 'number')) {
          $total = (float) $value;
        }
      }

      if ($code === '') {
        continue;
      }

      $rows[] = [
        'currency' => $code,
        'total' => $total,
        'formatted' => $this->money((string) $total, $code),
      ];

      $highest = max($highest, $total);
    }

    // Biggest first: the currency that matters most to the shop is the one
    // it takes the most in, and that is the line worth reading first.
    usort($rows, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

    $facts = ['Currencies' => (string) count($rows)] + $this->contextFacts();

    foreach ($rows as $row) {
      $facts[$row['currency']] = $row['formatted'];
    }

    return new Reading($highest, $rows, $facts, $cacheability);
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
