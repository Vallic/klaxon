<?php

declare(strict_types=1);

namespace Drupal\klaxon\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Declares a Klaxon alert type plugin.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class AlertType extends Plugin {

  public function __construct(
    public readonly string $id,
    public readonly ?TranslatableMarkup $label = NULL,
    public readonly ?TranslatableMarkup $description = NULL,
    // Groups the type under a heading in the picker. Once a site has a couple
    // of submodules installed the flat list stops being readable, and "which
    // of these is about the shop" is the first question anyone asks of it.
    public readonly ?TranslatableMarkup $category = NULL,
    public readonly ?string $deriver = NULL,
  ) {}

}
