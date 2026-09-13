<?php

declare(strict_types=1);

namespace Drupal\klaxon;

use Drupal\Core\Cache\CacheableMetadata;

/**
 * What a source measured, in the one shape everything downstream consumes.
 *
 * Rows are keyed by a stable identifier, such as an entity id or a queue name,
 * so an alert can remember which rows it has already reported. Without that
 * key an alert that fires once per matching row, "this auction ends within the
 * hour" being the obvious case, repeats itself on every evaluation.
 */
final class Reading {

  public function __construct(
    public readonly int|float|null $value = NULL,
    public readonly array $rows = [],
    public readonly array $context = [],
    public readonly CacheableMetadata $cacheability = new CacheableMetadata(),
  ) {}

  /**
   * A single measured number, such as a count or a sum.
   */
  public static function scalar(int|float $value, array $context = [], ?CacheableMetadata $cacheability = NULL): self {
    return new self($value, [], $context, $cacheability ?? new CacheableMetadata());
  }

  /**
   * A set of rows keyed by a stable identifier. The value is the row count.
   *
   * @param array $rows
   *   Rows keyed by a stable identifier. Each row is an associative array of
   *   values used to render the message.
   * @param array $context
   *   Extra values describing the measurement, shown as facts.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheability
   *   Cacheability of the data read, or NULL for none.
   *
   * @return self
   *   A reading whose value is the row count.
   */
  public static function fromRows(array $rows, array $context = [], ?CacheableMetadata $cacheability = NULL): self {
    return new self(count($rows), $rows, $context, $cacheability ?? new CacheableMetadata());
  }

  /**
   * Nothing matched. Distinct from a failed read, which throws.
   */
  public static function nothing(array $context = []): self {
    return new self(0, [], $context, new CacheableMetadata());
  }

  /**
   * The same reading narrowed to the given row keys, with the value re-counted.
   */
  public function only(array $keys): self {
    $rows = array_intersect_key($this->rows, array_flip($keys));
    return new self(count($rows), $rows, $this->context, $this->cacheability);
  }

  /**
   * TRUE when nothing was found: no rows and no non-zero value.
   */
  public function isEmpty(): bool {
    return $this->rows === [] && ($this->value === NULL || (float) $this->value === 0.0);
  }

  /**
   * The number a condition compares against: the scalar, or the row count.
   */
  public function measure(): int|float {
    return $this->value ?? count($this->rows);
  }

}
