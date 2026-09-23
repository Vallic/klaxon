<?php

declare(strict_types=1);

namespace Drupal\Tests\klaxon\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\klaxon\Entity\Alert;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the conditions that narrow an entity query alert.
 *
 * The query has always applied them; until this there was no form to put them
 * in, so an alert could only get them by editing configuration by hand - and
 * one that read "orders sitting in approve_verify" was quietly counting every
 * order there was.
 */
#[Group('klaxon')]
#[RunTestsInSeparateProcesses]
class EntityQueryConditionsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'entity_test', 'klaxon'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installSchema('klaxon', ['klaxon_state', 'klaxon_ledger']);
    $this->installConfig(['klaxon']);

    foreach (['keep', 'keep', 'drop'] as $name) {
      EntityTest::create(['name' => $name])->save();
    }
    // Nameless, for the emptiness operators.
    EntityTest::create([])->save();
  }

  /**
   * Each operator narrows the count to what it says.
   */
  public function testOperatorsNarrowTheCount(): void {
    $cases = [
      'equals' => [['field' => 'name', 'operator' => '=', 'value' => 'keep'], 2.0],
      'not equals' => [['field' => 'name', 'operator' => '<>', 'value' => 'keep'], 1.0],
      'one of' => [['field' => 'name', 'operator' => 'IN', 'value' => 'keep, drop'], 3.0],
      'none of' => [['field' => 'name', 'operator' => 'NOT IN', 'value' => 'keep'], 1.0],
      'starts with' => [['field' => 'name', 'operator' => 'STARTS_WITH', 'value' => 'ke'], 2.0],
      'is empty' => [['field' => 'name', 'operator' => 'IS NULL', 'value' => ''], 1.0],
      'is not empty' => [['field' => 'name', 'operator' => 'IS NOT NULL', 'value' => ''], 3.0],
    ];

    foreach ($cases as $case => [$condition, $expected]) {
      $this->assertSame($expected, $this->measure([$condition]), "Counted what '$case' asked for.");
    }
  }

  /**
   * Conditions add up, so several of them narrow further.
   */
  public function testConditionsCombine(): void {
    $measure = $this->measure([
      ['field' => 'name', 'operator' => 'IS NOT NULL', 'value' => ''],
      ['field' => 'name', 'operator' => '<>', 'value' => 'keep'],
    ]);

    $this->assertSame(1.0, $measure);
  }

  /**
   * Joined with "any", the conditions widen instead of narrowing.
   */
  public function testAnyConditionMatching(): void {
    $conditions = [
      ['field' => 'name', 'operator' => '=', 'value' => 'drop'],
      ['field' => 'name', 'operator' => 'IS NULL', 'value' => ''],
    ];

    $this->assertSame(0.0, $this->measure($conditions), 'Both at once is nothing.');
    $this->assertSame(2.0, $this->measure($conditions, 'OR'), 'Either one is two.');
  }

  /**
   * Matching any of them stays one term, so the rest still has to match.
   *
   * The window is the test: an OR that leaked would drag everything ever
   * created back into the count.
   */
  public function testAnyDoesNotLoosenTheRest(): void {
    $measure = $this->measure([
      ['field' => 'name', 'operator' => '=', 'value' => 'keep'],
      ['field' => 'name', 'operator' => '=', 'value' => 'drop'],
    ], 'OR', ['date_field' => 'created', 'window_from' => '+1 hour', 'window_to' => '+2 hours']);

    $this->assertSame(0.0, $measure, 'Nothing was created an hour from now.');
  }

  /**
   * A row with no field, or a list with nothing in it, narrows nothing.
   */
  public function testUnfinishedRowsAreIgnored(): void {
    $all = $this->measure([]);

    $this->assertSame($all, $this->measure([['field' => '', 'operator' => '=', 'value' => 'keep']]));
    $this->assertSame($all, $this->measure([['field' => 'name', 'operator' => 'IN', 'value' => ' , ']]));
  }

  /**
   * What the alert measures with the given conditions.
   *
   * @param array $conditions
   *   Condition rows, as the form stores them.
   * @param string $conjunction
   *   Whether every row has to match, or any of them.
   * @param array $extra
   *   Anything else the alert type should be configured with.
   *
   * @return float
   *   The measure.
   */
  protected function measure(array $conditions, string $conjunction = 'AND', array $extra = []): float {
    if ($alert = Alert::load('counted')) {
      $alert->delete();
    }

    Alert::create([
      'id' => 'counted',
      'label' => 'counted',
      'status' => TRUE,
      'type' => [
        'id' => 'entity_query',
        'entity_type' => 'entity_test',
        'aggregate' => 'count',
        'operator' => '>',
        'value' => 0,
        'conditions' => $conditions,
        'conjunction' => $conjunction,
      ] + $extra,
      'subject' => 'counted',
    ])->save();

    return Alert::load('counted')->getType()->read()->measure();
  }

}
