<?php

declare(strict_types=1);

namespace Drupal\klaxon\AlertType;

use Drupal\klaxon\KlaxonPluginInterface;
use Drupal\klaxon\Reading;

/**
 * What an alert is about, in one plugin.
 *
 * An alert type answers every question that used to be split across a trigger,
 * a source and a condition: when to look, what to look at, and what makes it
 * worth saying. They were separate plugin types once, and the result was a
 * matrix where most combinations were nonsense — a schedule with nothing to
 * measure, an entity save that then ran a site-wide query. One plugin per use
 * case is both easier to pick from a list and easier to write.
 *
 * How an alert gets evaluated follows from which interfaces it implements
 * rather than from anything the person configuring it picks:
 *
 * - Implement ScheduledAlertInterface and cron evaluates it.
 * - Implement EntityEventAlertInterface and entity saves evaluate it.
 * - Implement neither and only code firing the dispatcher evaluates it.
 */
interface AlertTypeInterface extends KlaxonPluginInterface {

  /**
   * Takes the measurement this alert is about.
   *
   * @param array $context
   *   Whatever the caller supplied. An entity event puts the entity and the
   *   operation here; code firing the dispatcher puts anything it likes.
   *
   * @return \Drupal\klaxon\Reading
   *   What is true right now. A type that found nothing returns an empty
   *   reading; a type that could not look at all throws.
   *
   * @throws \Drupal\klaxon\AlertType\ReadException
   *   When the measurement could not be taken. This is a broken alert, not a
   *   quiet one, and is logged rather than delivered.
   */
  public function read(array $context = []): Reading;

  /**
   * TRUE when this reading is worth telling someone about.
   *
   * Types that only run because something already happened accept everything.
   * Types that measure continuously — a scheduled query, most obviously —
   * compare the reading against whatever the person configured.
   */
  public function fires(Reading $reading): bool;

}
