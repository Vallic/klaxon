<?php

declare(strict_types=1);

namespace Drupal\klaxon_commerce\Plugin\Klaxon\AlertType;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\klaxon\Attribute\AlertType;
use Drupal\klaxon_commerce\OrderAlertBase;

/**
 * Carts with something in them that nobody came back for.
 *
 * A count that climbs is the interesting reading here, not the individual
 * carts: abandonment is normal, and a sudden change in how much of it there is
 * usually means something broke at checkout rather than that shoppers changed
 * their minds all at once.
 */
#[AlertType(
  id: 'commerce_abandoned_carts',
  label: new TranslatableMarkup('Carts left behind'),
  description: new TranslatableMarkup('Count carts that have something in them and have not been touched for a while. A jump in the number is usually a broken checkout, not a change of heart.'),
  category: new TranslatableMarkup('Commerce'),
)]
class AbandonedCarts extends OrderAlertBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'aggregate' => self::COUNT,
      'date_field' => 'changed',
      'date_storage' => 'timestamp',
      'hours' => 2,
      'window_from' => '-24 hours',
      'window_to' => '-2 hours',
      'interval' => 3600,
      'operator' => '>',
      'value' => 20,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  protected function isAboutCarts(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['stale'] = [
      '#type' => 'details',
      '#title' => $this->t('Which carts count as abandoned'),
      '#open' => TRUE,
      '#weight' => -5,
    ];

    $form['stale']['hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Untouched for at least'),
      '#field_suffix' => $this->t('hours'),
      '#min' => 1,
      '#default_value' => $this->configuration['hours'] ?? 2,
      '#required' => TRUE,
      '#description' => $this->t('Below an hour or so you are counting people who are still shopping.'),
    ];

    $form['stale']['window_from'] = [
      '#type' => 'textfield',
      '#title' => $this->t('And touched no earlier than'),
      '#default_value' => $this->configuration['window_from'] ?? '-24 hours',
      '#description' => $this->t('Keeps the count to recent abandonment rather than every cart the shop has ever accumulated, so the number stays comparable between runs.'),
    ];

    $form['fire']['#title'] = $this->t('How many is too many');

    // The states of a cart are not a useful question: a cart is a draft.
    unset($form['orders']['states']);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::submitConfigurationForm($form, $form_state);

    $this->setConfiguration([
      'hours' => (int) $form_state->getValue(['stale', 'hours']),
      'window_from' => (string) $form_state->getValue(['stale', 'window_from']),
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
    $hours = max(1, (int) ($this->configuration['hours'] ?? 2));

    $this->configuration['hours'] = $hours;
    $this->configuration['window_to'] = sprintf('-%d hours', $hours);
    // A cart is a draft. Its state is not a useful question.
    $this->configuration['states'] = [];
  }

  /**
   * {@inheritdoc}
   */
  protected function query(string $entity_type_id, bool $aggregate = FALSE) {
    $query = parent::query($entity_type_id, $aggregate);
    // An empty cart is not abandonment, it is a session.
    $query->exists('order_items');

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): string {
    return (string) new TranslatableMarkup('Carts untouched for @count hours, @test', [
      '@count' => (int) ($this->configuration['hours'] ?? 2),
      '@test' => $this->thresholdSummary(),
    ]);
  }

}
