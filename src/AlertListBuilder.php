<?php

declare(strict_types=1);

namespace Drupal\klaxon;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;
use Drupal\klaxon\Entity\AlertInterface;

/**
 * Lists the alerts, each summarised by what it is and what it watches.
 */
class AlertListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Alert');
    $header['type'] = $this->t('Kind');
    $header['summary'] = $this->t('What it watches');
    $header['channels'] = $this->t('Delivers to');
    $header['status'] = $this->t('Status');

    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof AlertInterface);

    $type = $entity->getType();

    $row['label'] = $entity->label();
    $row['type'] = (string) ($type->getPluginDefinition()['label'] ?? $entity->getTypeId());
    $row['summary'] = $type->summary();
    $row['channels'] = implode(', ', $entity->getChannelIds()) ?: $this->t('Nowhere');
    $row['status'] = $entity->status() ? $this->t('Enabled') : $this->t('Disabled');

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity, ?CacheableMetadata $cacheability = NULL): array {
    $operations = parent::getDefaultOperations($entity, $cacheability);

    $operations['run'] = [
      'title' => $this->t('Run now'),
      'weight' => 20,
      'url' => Url::fromRoute('klaxon.alert_run', ['klaxon_alert' => $entity->id()]),
    ];

    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build = parent::render();
    $build['table']['#empty'] = $this->t('No alerts yet. An alert is one thing worth knowing about, and where to say it.');

    return $build;
  }

}
