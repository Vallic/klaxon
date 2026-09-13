<?php

declare(strict_types=1);

namespace Drupal\klaxon\Plugin\Klaxon\AlertType;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\klaxon\AlertType\AlertTypeBase;
use Drupal\klaxon\Attribute\AlertType;
use Drupal\klaxon\Reading;

/**
 * Fires only when code says so, and says whatever the code handed over.
 *
 * The deliberate blank. Nothing evaluates this alert on its own: cron skips it
 * and no entity change reaches it. What it gives a developer is everything
 * around the message — templating, channels, severity, cooldown, the admin UI
 * — without prescribing where the facts came from:
 *
 * @code
 * \Drupal::service('klaxon.dispatcher')->fire('nightly_backup', [
 *   'rows' => ['srv-1' => ['label' => 'srv-1: disk full']],
 *   'facts' => ['Run' => 'nightly', 'Duration' => '41s'],
 * ]);
 * @endcode
 *
 * Anything specific enough to have its own logic belongs here rather than in a
 * form, and anything worth reusing belongs in its own alert type plugin.
 */
#[AlertType(
  id: 'code',
  label: new TranslatableMarkup('When code fires it'),
  description: new TranslatableMarkup('Nothing evaluates this alert automatically. Custom code calls the dispatcher when it has something to say, and passes in whatever the message should mention.'),
  category: new TranslatableMarkup('General'),
)]
class CodeFired extends AlertTypeBase {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['help'] = [
      '#type' => 'inline_template',
      '#template' => '<p>{{ intro }}</p><pre>{{ snippet }}</pre><p>{{ outro }}</p>',
      '#context' => [
        'intro' => $this->t('Fire it from code, passing anything the message should mention:'),
        'snippet' => "\\Drupal::service('klaxon.dispatcher')->fire('ALERT_ID', [\n  'value' => 12,\n  'rows' => ['srv-1' => ['label' => 'srv-1: disk full']],\n  'facts' => ['Server' => 'srv-1'],\n]);",
        'outro' => $this->t('Rows are keyed by a stable identifier, which is what lets this alert report each one only once. Facts are shown as a list under the message.'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function read(array $context = []): Reading {
    return $this->readFromContext($context);
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): string {
    return (string) new TranslatableMarkup('Fired by code');
  }

}
