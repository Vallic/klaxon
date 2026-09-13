<?php

declare(strict_types=1);

namespace Drupal\klaxon;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\klaxon\Entity\ChannelInterface;

/**
 * Lists the configured channels and where each one delivers.
 */
class ChannelListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Channel');
    $header['transport'] = $this->t('Delivers to');
    $header['status'] = $this->t('Status');

    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof ChannelInterface);

    $row['label'] = $entity->label();
    $row['transport'] = $entity->getTransport()->summary();
    $row['status'] = $entity->status() ? $this->t('Enabled') : $this->t('Disabled');

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build = parent::render();
    $build['table']['#empty'] = $this->t('No channels yet. A channel is somewhere alerts get delivered, such as an address or a chat room.');

    return $build;
  }

}
