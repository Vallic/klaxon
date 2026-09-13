<?php

declare(strict_types=1);

namespace Drupal\klaxon\AlertType;

use Cron\CronExpression;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\klaxon\Reading;

/**
 * Base for alert types cron evaluates and compares against a number.
 *
 * Two questions belong to every scheduled alert whatever it measures: how
 * often to look, and what number makes it worth saying. This holds both, so a
 * type that watches something other than content — a queue, a log, the clock
 * — inherits the schedule and the threshold and writes only its read().
 *
 * Subclasses that have already decided their threshold drop the section from
 * the form and set the operator themselves. Several do: "nothing has sold" is
 * only ever the empty case, and offering the choice would be offering a way to
 * get it wrong.
 */
abstract class ScheduledAlertBase extends AlertTypeBase implements ScheduledAlertInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      // A cron expression wins when set. Otherwise the interval is used, which
      // needs no external library.
      'cron' => '',
      'interval' => 3600,
      'operator' => '>',
      'value' => 0,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['schedule'] = $this->scheduleForm();
    $form['schedule']['#weight'] = 0;
    $form['fire'] = $this->thresholdForm();
    $form['fire']['#weight'] = 20;

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();

    // Each section is a details element. Flatten them back into flat config,
    // so a subclass dropping a whole section changes nothing about the shape
    // of what gets stored.
    foreach ($this->formSections() as $section) {
      foreach ((array) ($values[$section] ?? []) as $key => $value) {
        $values[$key] = $value;
      }
      unset($values[$section]);
    }

    $this->setConfiguration($this->normalizeValues($values));
  }

  /**
   * A last pass over the flattened values before they become configuration.
   *
   * For anything the form had to wrap for its own reasons — an AJAX container
   * most obviously — and that has no business being stored in that shape.
   */
  protected function normalizeValues(array $values): array {
    return $values;
  }

  /**
   * The form sections whose values are flattened into configuration.
   *
   * @return string[]
   *   Section keys, in the order they are flattened.
   */
  protected function formSections(): array {
    return ['schedule', 'fire'];
  }

  /**
   * How often to look.
   */
  protected function scheduleForm(): array {
    $form = [
      '#type' => 'details',
      '#title' => $this->t('How often to check'),
      '#open' => TRUE,
    ];

    $form['interval'] = [
      '#type' => 'select',
      '#title' => $this->t('Check every'),
      '#options' => [
        300 => $this->t('5 minutes'),
        600 => $this->t('10 minutes'),
        900 => $this->t('15 minutes'),
        1800 => $this->t('30 minutes'),
        3600 => $this->t('Hour'),
        10800 => $this->t('3 hours'),
        86400 => $this->t('Day'),
      ],
      '#default_value' => $this->configuration['interval'] ?? 3600,
      '#description' => $this->t('Alerts are evaluated on cron, so this is a floor rather than a guarantee.'),
    ];

    $form['cron'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Or a cron expression'),
      '#default_value' => $this->configuration['cron'] ?? '',
      '#placeholder' => '0 7 * * *',
      '#description' => $this->t('Five fields, as in crontab. Overrides the interval above when set, and is the way to say "at 7am", which an interval cannot.'),
    ];

    return $form;
  }

  /**
   * What makes it worth saying.
   */
  protected function thresholdForm(): array {
    $form = [
      '#type' => 'details',
      '#title' => $this->t('When to fire'),
      '#open' => TRUE,
    ];

    $form['operator'] = [
      '#type' => 'select',
      '#title' => $this->t('Fire when the measured value'),
      '#options' => [
        '>' => $this->t('is above'),
        '>=' => $this->t('is at least'),
        '<' => $this->t('is below'),
        '<=' => $this->t('is at most'),
        '==' => $this->t('equals'),
        '!=' => $this->t('is not'),
        'not_empty' => $this->t('found something'),
        'empty' => $this->t('found nothing, a dead man switch'),
      ],
      '#default_value' => $this->configuration['operator'] ?? '>',
      '#description' => $this->t('"Found nothing" is the one worth knowing about: it fires when a query that should return something returns nothing, which is how you say "no orders in the last half hour".'),
    ];

    $form['value'] = [
      '#type' => 'number',
      '#title' => $this->t('Value'),
      '#step' => 'any',
      '#default_value' => $this->configuration['value'] ?? 0,
      '#states' => [
        'invisible' => [
          ':input[name$="[operator]"]' => [
            ['value' => 'empty'],
            'or',
            ['value' => 'not_empty'],
          ],
        ],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function isDue(int $now, ?int $last_run): bool {
    // Never run before, so it is due whatever the schedule says.
    if ($last_run === NULL || $last_run === 0) {
      return TRUE;
    }

    $expression = (string) ($this->configuration['cron'] ?? '');

    if ($expression !== '' && class_exists(CronExpression::class)) {
      try {
        $next = (new CronExpression($expression))
          ->getNextRunDate('@' . $last_run)
          ->getTimestamp();

        return $next <= $now;
      }
      catch (\Throwable) {
        // An unparseable expression falls back to the interval rather than
        // silently never running.
      }
    }

    $interval = max(60, (int) ($this->configuration['interval'] ?? 3600));

    return ($last_run + $interval) <= $now;
  }

  /**
   * {@inheritdoc}
   */
  public function fires(Reading $reading): bool {
    $operator = (string) ($this->configuration['operator'] ?? '>');

    if ($operator === 'empty') {
      return $reading->isEmpty();
    }

    if ($operator === 'not_empty') {
      return !$reading->isEmpty();
    }

    $measure = $reading->measure();
    $threshold = (float) ($this->configuration['value'] ?? 0);

    return match ($operator) {
      '>' => $measure > $threshold,
      '>=' => $measure >= $threshold,
      '<' => $measure < $threshold,
      '<=' => $measure <= $threshold,
      '==' => (float) $measure === $threshold,
      '!=' => (float) $measure !== $threshold,
      default => FALSE,
    };
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): string {
    return (string) new TranslatableMarkup('@label, @test', [
      '@label' => $this->pluginDefinition['label'] ?? $this->getPluginId(),
      '@test' => $this->thresholdSummary(),
    ]);
  }

  /**
   * The threshold in words, for the summary line.
   */
  protected function thresholdSummary(): string {
    $operator = (string) ($this->configuration['operator'] ?? '>');

    return (string) match ($operator) {
      'empty' => new TranslatableMarkup('when nothing is found'),
      'not_empty' => new TranslatableMarkup('when something is found'),
      // Operators are spelled out rather than passed through as symbols,
      // because a placeholder escapes "<" and ">" into entities in the listing.
      '>' => new TranslatableMarkup('when above @value', ['@value' => $this->configuration['value'] ?? 0]),
      '>=' => new TranslatableMarkup('when at least @value', ['@value' => $this->configuration['value'] ?? 0]),
      '<' => new TranslatableMarkup('when below @value', ['@value' => $this->configuration['value'] ?? 0]),
      '<=' => new TranslatableMarkup('when at most @value', ['@value' => $this->configuration['value'] ?? 0]),
      '==' => new TranslatableMarkup('when it equals @value', ['@value' => $this->configuration['value'] ?? 0]),
      '!=' => new TranslatableMarkup('when it is not @value', ['@value' => $this->configuration['value'] ?? 0]),
      default => new TranslatableMarkup('never'),
    };
  }

}
