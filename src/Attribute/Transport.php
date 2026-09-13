<?php

declare(strict_types=1);

namespace Drupal\klaxon\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Declares a Klaxon Transport plugin.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class Transport extends Plugin {

  public function __construct(
    public readonly string $id,
    public readonly ?TranslatableMarkup $label = NULL,
    public readonly ?TranslatableMarkup $description = NULL,
    // Groups the transport under a heading in the channel form, the same way
    // an alert type is grouped in the alert form.
    public readonly ?TranslatableMarkup $category = NULL,
    public readonly ?string $deriver = NULL,
  ) {}

}
