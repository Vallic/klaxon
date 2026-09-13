<?php

declare(strict_types=1);

namespace Drupal\klaxon\Alert;

use Drupal\Core\Database\Connection;

/**
 * Remembers what each alert did last time, so it does not repeat itself.
 *
 * Two things are recorded. Per alert: when it ran, what it measured and
 * whether it is currently firing, which is what makes "tell me when it starts,
 * not every ten minutes" possible. Per row: which individual rows have already
 * been reported, which is what makes "announce each auction once" possible.
 */
class StateStore {

  public function __construct(
    protected readonly Connection $database,
  ) {}

  /**
   * The recorded state of an alert, with defaults for one that never ran.
   */
  public function get(string $alert_id): AlertState {
    $row = $this->database->select('klaxon_state', 's')
      ->fields('s')
      ->condition('alert_id', $alert_id)
      ->execute()
      ->fetchAssoc();

    if ($row === FALSE || $row === NULL) {
      return new AlertState($alert_id);
    }

    return new AlertState(
      $alert_id,
      (int) $row['last_run'] ?: NULL,
      (int) $row['last_fired'] ?: NULL,
      $row['last_value'] === NULL ? NULL : (float) $row['last_value'],
      (string) $row['status'],
      (int) $row['fire_count'],
    );
  }

  /**
   * Writes the state back, inserting the row if it is new.
   */
  public function save(AlertState $state): void {
    $this->database->merge('klaxon_state')
      ->key('alert_id', $state->alertId)
      ->fields([
        'last_run' => $state->lastRun ?? 0,
        'last_fired' => $state->lastFired ?? 0,
        'last_value' => $state->lastValue,
        'status' => $state->status,
        'fire_count' => $state->fireCount,
      ])
      ->execute();
  }

  /**
   * Of the given row keys, the ones this alert has not reported yet.
   *
   * @param string $alert_id
   *   The alert to check against.
   * @param string[] $keys
   *   Candidate row keys from the current reading.
   *
   * @return string[]
   *   The keys this alert has not reported before.
   */
  public function unseenRowKeys(string $alert_id, array $keys): array {
    if ($keys === []) {
      return [];
    }

    $seen = $this->database->select('klaxon_ledger', 'l')
      ->fields('l', ['row_key'])
      ->condition('alert_id', $alert_id)
      ->condition('row_key', $keys, 'IN')
      ->execute()
      ->fetchCol();

    return array_values(array_diff($keys, array_map('strval', $seen)));
  }

  /**
   * Records that these rows have now been reported.
   *
   * @param string $alert_id
   *   The alert doing the reporting.
   * @param string[] $keys
   *   Row keys now considered reported.
   * @param int $now
   *   Timestamp to record against them.
   */
  public function markRowsReported(string $alert_id, array $keys, int $now): void {
    foreach ($keys as $key) {
      $this->database->merge('klaxon_ledger')
        ->keys(['alert_id' => $alert_id, 'row_key' => (string) $key])
        ->fields(['fired' => $now])
        ->execute();
    }
  }

  /**
   * Drops ledger rows older than the given cutoff, so the table stays small.
   */
  public function pruneLedger(int $before): int {
    return (int) $this->database->delete('klaxon_ledger')
      ->condition('fired', $before, '<')
      ->execute();
  }

  /**
   * Forgets everything about an alert. Called when one is deleted.
   */
  public function forget(string $alert_id): void {
    $this->database->delete('klaxon_state')->condition('alert_id', $alert_id)->execute();
    $this->database->delete('klaxon_ledger')->condition('alert_id', $alert_id)->execute();
  }

}
