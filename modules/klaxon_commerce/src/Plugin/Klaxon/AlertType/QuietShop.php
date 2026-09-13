<?php

declare(strict_types=1);

namespace Drupal\klaxon_commerce\Plugin\Klaxon\AlertType;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\klaxon\Attribute\AlertType;
use Drupal\klaxon_commerce\OrderAlertBase;

/**
 * Nothing has sold for a while.
 *
 * The dead man's switch, and usually the most valuable alert a shop can have.
 * It is also the one nobody writes, because the logic reads backwards: every
 * other alert fires on something appearing, and this one fires on nothing
 * appearing. Written as its own type, there is nothing to get backwards — the
 * only question is how much silence counts as a problem.
 *
 * A broken checkout, an expired payment credential and a failed deploy all look
 * identical from the outside, and all look like this.
 */
#[AlertType(
  id: 'commerce_quiet',
  label: new TranslatableMarkup('Nothing has sold'),
  description: new TranslatableMarkup('Fire when no order has been placed for a while. Catches a broken checkout long before anybody reports it.'),
  category: new TranslatableMarkup('Commerce'),
)]
class QuietShop extends OrderAlertBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'aggregate' => self::COUNT,
      'date_field' => 'placed',
      'date_storage' => 'timestamp',
      'window_to' => 'now',
      'minutes' => 30,
      'window_from' => '-30 minutes',
      'interval' => 600,
      // Not configurable: finding nothing is the entire alert.
      'operator' => 'empty',
      'value' => 0,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    // There is no threshold to pick. The alert is the absence.
    unset($form['fire']);

    $form['silence'] = [
      '#type' => 'details',
      '#title' => $this->t('How much silence is a problem'),
      '#open' => TRUE,
      '#weight' => -5,
    ];

    $form['silence']['minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Quiet for'),
      '#field_suffix' => $this->t('minutes'),
      '#min' => 1,
      '#default_value' => $this->configuration['minutes'] ?? 30,
      '#required' => TRUE,
      '#description' => $this->t('Set it above the longest gap between orders on your quietest night, or the alert will cry wolf every Sunday at four in the morning.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::submitConfigurationForm($form, $form_state);

    $this->setConfiguration([
      'minutes' => (int) $form_state->getValue(['silence', 'minutes']),
    ] + $this->getConfiguration());
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): void {
    parent::setConfiguration($configuration);

    // Derived here rather than on form submit, because configuration arrives
    // three ways — a form, a config import, and code creating the alert — and
    // only one of them goes through a form. Still stored, so an exported alert
    // says what it actually queries instead of hiding it in code.
    $minutes = max(1, (int) ($this->configuration['minutes'] ?? 30));

    $this->configuration['minutes'] = $minutes;
    $this->configuration['window_from'] = sprintf('-%d minutes', $minutes);
    $this->configuration['window_to'] = 'now';
    $this->configuration['operator'] = 'empty';
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): string {
    return (string) new TranslatableMarkup('No @types placed in @count minutes', [
      '@types' => $this->orderTypeSummary(),
      '@count' => (int) ($this->configuration['minutes'] ?? 30),
    ]);
  }

}
