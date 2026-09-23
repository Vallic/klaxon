<?php

declare(strict_types=1);

namespace Drupal\Tests\klaxon\Unit;

use Drupal\Core\Form\FormState;
use Drupal\Tests\UnitTestCase;
use Drupal\klaxon\Plugin\Klaxon\AlertType\EntityQuery;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the buttons that grow and shrink the list of conditions.
 *
 * A rebuilt form takes an element's value from the raw input over its default,
 * so removing a row has to rewrite that input too. Without it the row goes but
 * the typing stays where it was, and deleting the first of two rows redraws
 * the survivor holding the deleted row's answers.
 */
#[Group('klaxon')]
class ConditionRowsTest extends UnitTestCase {

  /**
   * Where the conditions sit inside the alert form.
   */
  protected const PARENTS = ['type_settings', 'measure', 'conditions'];

  /**
   * The form state key the rows are kept under.
   */
  protected const ROWS_KEY = 'klaxon_condition_rows:entity_query';

  /**
   * Removing a row leaves the others, answers and all.
   */
  public function testRemovingRowKeepsOtherAnswers(): void {
    $form_state = $this->formState(0);
    $form = [];

    EntityQuery::removeCondition($form, $form_state);

    $expected = [['field' => 'name', 'operator' => '=', 'value' => 'admin']];

    $this->assertSame($expected, $form_state->get(self::ROWS_KEY), 'The row that stayed is the one not removed.');
    $this->assertSame(
      $expected,
      $form_state->getUserInput()['type_settings']['measure']['conditions']['rows'],
      'And it is what the rebuilt form will read back.',
    );
    $this->assertTrue($form_state->isRebuilding());
  }

  /**
   * Adding a row leaves what is already typed where it is.
   */
  public function testAddingRowKeepsWhatIsTyped(): void {
    $form_state = $this->formState(NULL);
    $form = [];

    EntityQuery::addCondition($form, $form_state);

    $rows = $form_state->get(self::ROWS_KEY);

    $this->assertCount(3, $rows);
    $this->assertSame('mail', $rows[0]['field']);
    $this->assertSame('admin', $rows[1]['value']);
    $this->assertSame(['field' => '', 'operator' => '=', 'value' => ''], $rows[2], 'The new one is empty.');
  }

  /**
   * A form state mid-edit, with two rows typed into.
   *
   * @param int|null $delta
   *   The row a Remove button was pressed on, or NULL for the Add button.
   */
  protected function formState(?int $delta): FormState {
    $form_state = new FormState();

    $form_state->setUserInput([
      'type_settings' => [
        'measure' => [
          'conditions' => [
            'rows' => [
              ['field' => 'mail', 'operator' => 'CONTAINS', 'value' => '@foreo'],
              ['field' => 'name', 'operator' => '=', 'value' => 'admin'],
            ],
          ],
        ],
      ],
    ]);

    $button = [
      '#parents' => $delta === NULL
        ? array_merge(self::PARENTS, ['add'])
        : array_merge(self::PARENTS, ['rows', $delta, 'remove']),
      '#klaxon_rows_key' => self::ROWS_KEY,
    ];

    if ($delta !== NULL) {
      $button['#klaxon_delta'] = $delta;
    }

    $form_state->setTriggeringElement($button);

    return $form_state;
  }

}
