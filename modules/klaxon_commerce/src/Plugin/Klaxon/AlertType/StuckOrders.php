<?php

declare(strict_types=1);

namespace Drupal\klaxon_commerce\Plugin\Klaxon\AlertType;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\klaxon\Attribute\AlertType;
use Drupal\klaxon_commerce\OrderAlertBase;

/**
 * Orders that have sat in one state for too long.
 *
 * Every shop has a state orders are not supposed to rest in — awaiting
 * validation, on hold, authorized but never captured — and no shop notices
 * when they do, because nothing looks broken. The order is fine. It is just
 * not moving.
 *
 * Turn on "report each match only once" on the alert and each order is named
 * the first time it goes stale, rather than every hour until someone acts.
 */
#[AlertType(
  id: 'commerce_stuck_orders',
  label: new TranslatableMarkup('Orders stuck in a state'),
  description: new TranslatableMarkup('List orders that have sat in a state longer than they should have. The states worth watching are the ones nothing moves an order out of automatically.'),
  category: new TranslatableMarkup('Commerce'),
)]
class StuckOrders extends OrderAlertBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'aggregate' => self::ROWS,
      // Not "placed": an order that changed state an hour ago is not stuck,
      // however long ago it was placed.
      'date_field' => 'changed',
      'date_storage' => 'timestamp',
      'hours' => 4,
      'window_from' => '',
      'window_to' => '-4 hours',
      'interval' => 3600,
      'limit' => 50,
      'operator' => 'not_empty',
      'value' => 0,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    unset($form['fire']);

    $form['stale'] = [
      '#type' => 'details',
      '#title' => $this->t('How long is too long'),
      '#open' => TRUE,
      '#weight' => -5,
    ];

    $form['stale']['hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Untouched for'),
      '#field_suffix' => $this->t('hours'),
      '#min' => 1,
      '#default_value' => $this->configuration['hours'] ?? 4,
      '#required' => TRUE,
      '#description' => $this->t('Counted from the last time the order changed at all, not from when it was placed.'),
    ];

    $form['stale']['limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Most orders to name'),
      '#min' => 1,
      '#default_value' => $this->configuration['limit'] ?? 50,
      '#description' => $this->t('A backlog of nine hundred is a number, not a list. This keeps the message readable.'),
    ];

    $form['orders']['states']['#required'] = TRUE;
    $form['orders']['states']['#description'] = $this->t('Required here: every order is permanently "stuck" in completed or canceled, so an alert with no state picked would report the entire shop.');

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::submitConfigurationForm($form, $form_state);

    $this->setConfiguration([
      'hours' => (int) $form_state->getValue(['stale', 'hours']),
      'limit' => (int) $form_state->getValue(['stale', 'limit']),
    ] + $this->getConfiguration());
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): void {
    parent::setConfiguration($configuration);

    // Derived here rather than on form submit, because configuration arrives
    // three ways — a form, a config import, and code creating the alert — and
    // only one of them goes through a form.
    $hours = max(1, (int) ($this->configuration['hours'] ?? 4));

    $this->configuration['hours'] = $hours;
    // Nothing since: everything that last changed before the cutoff.
    $this->configuration['window_from'] = '';
    $this->configuration['window_to'] = sprintf('-%d hours', $hours);
    $this->configuration['limit'] = max(1, (int) ($this->configuration['limit'] ?? 50));
    $this->configuration['operator'] = 'not_empty';
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): string {
    $states = array_filter((array) ($this->configuration['states'] ?? []));

    return (string) new TranslatableMarkup('@types in @states, untouched for @count hours', [
      '@types' => ucfirst($this->orderTypeSummary()),
      '@states' => $states === [] ? (string) new TranslatableMarkup('any state') : implode(', ', $states),
      '@count' => (int) ($this->configuration['hours'] ?? 4),
    ]);
  }

}
