<?php

declare(strict_types=1);

namespace Drupal\Tests\klaxon\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Render\Element;
use Drupal\KernelTests\KernelTestBase;
use Drupal\entity_test\EntityTestHelper;
use Drupal\klaxon\Entity\Alert;
use Drupal\klaxon\Entity\Channel;
use Drupal\klaxon\Plugin\Klaxon\AlertType\EntityQuery;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers the alert form: the steps it asks in, and what they store.
 *
 * The middle step is the one place where a plugin's settings cross from a form
 * into configuration, and the nesting it travels through is invisible until it
 * breaks. Most of this is about that crossing, and about each step leaving the
 * steps it did not ask about alone.
 */
#[Group('klaxon')]
#[RunTestsInSeparateProcesses]
class AlertFormTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'entity_test', 'klaxon'];

  /**
   * The step numbers, so the tests read as the form does.
   */
  protected const BASICS = 0;
  protected const SETTINGS = 1;
  protected const DELIVERY = 2;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installSchema('klaxon', ['klaxon_state', 'klaxon_ledger']);
    $this->installConfig(['klaxon']);

    Channel::create([
      'id' => 'c',
      'label' => 'Test channel',
      'status' => TRUE,
      'transport' => ['id' => 'log'],
    ])->save();
  }

  /**
   * The types are offered grouped by category, not as one flat list.
   */
  public function testTypesAreGroupedByCategory(): void {
    $options = $this->buildStep(self::BASICS)['type_id']['#options'];

    $this->assertArrayHasKey('General', $options, 'The shipped types are grouped.');
    $this->assertArrayHasKey('entity_query', $options['General']);
    $this->assertArrayHasKey('entity_event', $options['General']);
    $this->assertArrayHasKey('code', $options['General']);
  }

  /**
   * Each step asks its own questions and none of the others.
   */
  public function testEachStepAsksItsOwnQuestions(): void {
    $basics = $this->buildStep(self::BASICS);

    $this->assertArrayHasKey('label', $basics);
    $this->assertArrayHasKey('type_id', $basics);
    $this->assertArrayNotHasKey('type_settings', $basics, 'The type is picked before it is configured.');
    $this->assertArrayNotHasKey('delivery', $basics);

    $settings = $this->buildStep(self::SETTINGS);

    $this->assertArrayHasKey('type_settings', $settings);
    $this->assertArrayNotHasKey('type_id', $settings, 'The picker is behind us.');
    $this->assertArrayNotHasKey('delivery', $settings);

    $delivery = $this->buildStep(self::DELIVERY);

    $this->assertArrayHasKey('channels', $delivery['delivery']);
    $this->assertArrayHasKey('subject', $delivery['message']);
    $this->assertArrayNotHasKey('type_settings', $delivery);
  }

  /**
   * The chosen type explains itself above its own settings.
   */
  public function testTheChosenTypeExplainsItself(): void {
    $settings = $this->buildStep(self::SETTINGS);

    $this->assertNotEmpty($settings['type_description']['#value']);

    // The explanation is a sibling of the settings, not a member of them: a
    // plugin is free to have a setting of its own called "description".
    $this->assertArrayNotHasKey('description', $settings['type_settings']);
  }

  /**
   * Walking the three steps stores what each of them asked.
   */
  public function testWalkingTheStepsStoresEverything(): void {
    EntityTestHelper::createBundle('article', 'Article', 'entity_test');

    $alert = $this->walk([
      self::BASICS => [
        'label' => 'No new accounts today',
        'id' => 'quiet_accounts',
        'type_id' => 'entity_query',
      ],
      self::SETTINGS => [
        'type_settings' => [
          'schedule' => ['interval' => 600, 'cron' => ''],
          'measure' => [
            'entity_type' => 'entity_test',
            'bundles_wrapper' => ['bundle' => 'article'],
            'aggregate' => 'count',
            'date_field' => 'created',
            'window_from' => '-24 hours',
            'window_to' => 'now',
          ],
          'fire' => ['operator' => 'empty', 'value' => 0],
        ],
      ],
      self::DELIVERY => [
        'channels' => ['c' => 'c'],
        'severity' => 'critical',
        'cooldown' => 1800,
      ],
    ]);

    $this->assertSame('entity_query', $alert->getTypeId());
    $this->assertSame(['c'], $alert->getChannelIds());
    $this->assertSame(1800, $alert->getCooldown());
    $this->assertSame('critical', $alert->getSeverity());

    $config = $alert->getType()->getConfiguration();

    // The form's sections are flattened back out on the way in, so the stored
    // shape does not mirror the form's nesting.
    $this->assertSame('entity_test', $config['entity_type']);
    $this->assertSame('article', $config['bundle'], 'The bundle came out of its AJAX container.');
    $this->assertSame('created', $config['date_field']);
    $this->assertSame('-24 hours', $config['window_from']);
    $this->assertSame('empty', $config['operator']);
    $this->assertSame(600, $config['interval']);
    $this->assertArrayNotHasKey('measure', $config, 'No form scaffolding leaked into config.');
    $this->assertArrayNotHasKey('bundles_wrapper', $config);
  }

  /**
   * Conditions typed into the form come back out as configuration.
   *
   * The rows are plain form elements rather than an AJAX list, so the blanks
   * offered alongside the saved ones have to fall away by themselves.
   */
  public function testConditionRowsSurviveTheForm(): void {
    $alert = $this->walk([
      self::BASICS => ['label' => 'Stuck', 'id' => 'stuck', 'type_id' => 'entity_query'],
      self::SETTINGS => [
        'type_settings' => [
          'measure' => [
            'entity_type' => 'entity_test',
            'aggregate' => 'count',
            'conditions' => [
              'conjunction' => 'OR',
              'rows' => [
                ['field' => 'name', 'operator' => '=', 'value' => ' keep '],
                // Asks about the field itself, so its value is dropped.
                ['field' => 'name', 'operator' => 'IS NOT NULL', 'value' => 'ignored'],
                // The row left empty.
                ['field' => '', 'operator' => '=', 'value' => ''],
              ],
            ],
          ],
          'fire' => ['operator' => '>', 'value' => 0],
        ],
      ],
      self::DELIVERY => ['channels' => ['c' => 'c']],
    ], NULL, [
      self::SETTINGS => ['klaxon_condition_rows:entity_query' => array_fill(0, 3, [])],
    ]);

    $config = $alert->getType()->getConfiguration();

    $this->assertSame([
      ['field' => 'name', 'operator' => '=', 'value' => 'keep'],
      ['field' => 'name', 'operator' => 'IS NOT NULL', 'value' => ''],
    ], $config['conditions'], 'Trimmed, blanks dropped, renumbered.');
    $this->assertSame('OR', $config['conjunction'], 'And they are joined the way the form said.');
  }

  /**
   * The fields on offer are the ones the chosen entity type has.
   */
  public function testConditionFieldsComeFromTheEntityType(): void {
    $settings = $this->settingsFor(['id' => 'entity_query', 'entity_type' => 'entity_test']);
    $options = $settings['measure']['conditions']['rows'][0]['field']['#options'];

    $this->assertArrayHasKey('name', $options, 'Pickable, not typed from memory.');
    $this->assertStringContainsString('name', (string) $options['name'], 'The machine name is in the label.');
    $this->assertArrayNotHasKey('mail', $options, 'A field of some other entity type is not offered.');
  }

  /**
   * A field written by hand keeps its row even when it is not on offer.
   */
  public function testFieldNotOnOfferIsKept(): void {
    $settings = $this->settingsFor([
      'id' => 'entity_query',
      'entity_type' => 'entity_test',
      'conditions' => [['field' => 'name.value', 'operator' => '=', 'value' => 'x']],
    ]);

    $this->assertArrayHasKey('name.value', $settings['measure']['conditions']['rows'][0]['field']['#options']);
  }

  /**
   * A row can be added and taken away again, without leaving the step.
   */
  public function testRowsAreAddedAndRemoved(): void {
    $settings = $this->settingsFor([
      'id' => 'entity_query',
      'entity_type' => 'entity_test',
      'conditions' => [['field' => 'name', 'operator' => '=', 'value' => 'keep']],
    ]);
    $conditions = $settings['measure']['conditions'];

    $this->assertCount(1, Element::children($conditions['rows']), 'What was saved, and no filler.');
    $this->assertArrayHasKey('add', $conditions);
    $this->assertSame([EntityQuery::class, 'addCondition'], $conditions['add']['#submit'][0]);

    // A single row has nothing to be joined to, and removing it would leave
    // the section with no way back.
    $this->assertFalse($conditions['conjunction']['#access']);
    $this->assertFalse($conditions['rows'][0]['remove']['#access']);

    $two_rows = $this->settingsFor([
      'id' => 'entity_query',
      'entity_type' => 'entity_test',
      'conditions' => [
        ['field' => 'name', 'operator' => '=', 'value' => 'keep'],
        ['field' => 'name', 'operator' => '<>', 'value' => 'drop'],
      ],
    ], 'two');
    $conditions = $two_rows['measure']['conditions'];

    $this->assertTrue($conditions['conjunction']['#access'], 'Two rows can be joined either way.');
    $this->assertTrue($conditions['rows'][1]['remove']['#access']);
    $this->assertNotSame(
      $conditions['rows'][0]['remove']['#name'],
      $conditions['rows'][1]['remove']['#name'],
      'Each Remove submits as itself.',
    );
  }

  /**
   * Changing the entity type reloads the bundles and the field lists.
   */
  public function testTheEntityTypeSelectReloadsWhatDependsOnIt(): void {
    $settings = $this->settingsFor(['id' => 'entity_query', 'entity_type' => 'entity_test']);

    $this->assertSame(
      [EntityQuery::class, 'updateForEntityType'],
      $settings['measure']['entity_type']['#ajax']['callback'],
    );
    $this->assertNotEmpty($settings['measure']['bundles_wrapper']['#attributes']['id']);
    $this->assertNotEmpty($settings['measure']['conditions']['#attributes']['id'], 'The conditions can be replaced too.');
  }

  /**
   * Bundles are offered as a list when the entity type has any.
   */
  public function testBundlesAreOfferedWhenTheEntityTypeHasThem(): void {
    EntityTestHelper::createBundle('article', 'Article', 'entity_test');
    EntityTestHelper::createBundle('page', 'Basic page', 'entity_test');

    $settings = $this->settingsFor([
      'id' => 'entity_event',
      'entity_type' => 'entity_test',
      'operations' => ['insert'],
    ]);

    $bundles = $settings['bundles_wrapper']['bundles'];

    $this->assertSame('checkboxes', $bundles['#type'], 'Pickable, not typed from memory.');
    $this->assertSame('Article', (string) $bundles['#options']['article']);
    $this->assertSame('Basic page', (string) $bundles['#options']['page']);
  }

  /**
   * An entity type with no bundles is not asked about them.
   */
  public function testNoBundleWidgetWhenThereAreNoBundles(): void {
    $settings = $this->settingsFor([
      'id' => 'entity_event',
      'entity_type' => 'user',
      'operations' => ['insert'],
    ]);

    $this->assertArrayHasKey('bundles_wrapper', $settings, 'The container stays, for AJAX to replace.');
    $this->assertArrayNotHasKey('bundles', $settings['bundles_wrapper'], 'But there is nothing to ask.');
  }

  /**
   * Switching type on the first step starts that type from its own defaults.
   */
  public function testSwitchingTypeDiscardsTheOldSettings(): void {
    $alert = $this->walk([
      self::BASICS => ['label' => 'Was a query', 'id' => 'switcher', 'type_id' => 'entity_query'],
      self::SETTINGS => [
        'type_settings' => [
          'measure' => ['entity_type' => 'entity_test', 'aggregate' => 'count'],
          'fire' => ['operator' => '>', 'value' => 7],
        ],
      ],
      self::DELIVERY => ['channels' => ['c' => 'c']],
    ]);

    $alert = $this->walk([
      self::BASICS => ['label' => 'Now an event', 'id' => 'switcher', 'type_id' => 'entity_event'],
    ], $alert);

    $config = $alert->getType()->getConfiguration();

    $this->assertSame('entity_event', $alert->getTypeId());
    $this->assertArrayNotHasKey('operator', $config, 'The old type took its settings with it.');
  }

  /**
   * Keeping the type keeps its settings, so passing back through changes none.
   */
  public function testKeepingTheTypeKeepsItsSettings(): void {
    $alert = $this->walk([
      self::BASICS => ['label' => 'Kept', 'id' => 'kept', 'type_id' => 'entity_query'],
      self::SETTINGS => [
        'type_settings' => [
          'measure' => ['entity_type' => 'entity_test', 'aggregate' => 'count'],
          'fire' => ['operator' => '>', 'value' => 7],
        ],
      ],
      self::DELIVERY => ['channels' => ['c' => 'c'], 'severity' => 'critical'],
    ]);

    // Renaming it is the whole of an edit that only touches the first step.
    $alert = $this->walk([
      self::BASICS => ['label' => 'Renamed', 'id' => 'kept', 'type_id' => 'entity_query'],
    ], $alert);

    $config = $alert->getType()->getConfiguration();

    $this->assertSame('Renamed', $alert->label());
    $this->assertSame('entity_test', $config['entity_type'], 'The settings step was left alone.');
    $this->assertSame(7, (int) $config['value']);
    $this->assertSame(['c'], $alert->getChannelIds(), 'And so was the delivery step.');
    $this->assertSame('critical', $alert->getSeverity());
  }

  /**
   * The middle step saved on its own leaves the other two as they were.
   */
  public function testSavingFromTheMiddleStepKeepsTheRest(): void {
    $alert = $this->walk([
      self::BASICS => ['label' => 'Middle', 'id' => 'middle', 'type_id' => 'entity_query'],
      self::SETTINGS => [
        'type_settings' => [
          'measure' => ['entity_type' => 'entity_test', 'aggregate' => 'count'],
          'fire' => ['operator' => '>', 'value' => 1],
        ],
      ],
      self::DELIVERY => ['channels' => ['c' => 'c'], 'cooldown' => 900],
    ]);

    $alert = $this->walk([
      self::SETTINGS => [
        'type_settings' => [
          'measure' => ['entity_type' => 'entity_test', 'aggregate' => 'count'],
          'fire' => ['operator' => '>', 'value' => 42],
        ],
      ],
    ], $alert);

    $this->assertSame(42, (int) $alert->getType()->getConfiguration()['value']);
    $this->assertSame('Middle', $alert->label());
    $this->assertSame(900, $alert->getCooldown());
  }

  /**
   * Builds one step of the add form, or of an existing alert's edit form.
   */
  protected function buildStep(int $step, ?Alert $alert = NULL): array {
    $form_object = $this->container->get('entity_type.manager')
      ->getFormObject('klaxon_alert', $alert === NULL ? 'add' : 'edit');
    $form_object->setEntity($alert ?? $this->container->get('entity_type.manager')
      ->getStorage('klaxon_alert')
      ->create([]));

    $form_state = new FormState();
    $form_state->set('klaxon_step', $step);
    $form_state->addBuildInfo('args', []);

    return $this->container->get('form_builder')->buildForm($form_object, $form_state);
  }

  /**
   * The built settings step for an alert of the given type.
   */
  protected function settingsFor(array $type, string $id = 'probe'): array {
    $alert = Alert::create(['id' => $id, 'label' => 'Probe', 'type' => $type]);
    $alert->save();

    return $this->buildStep(self::SETTINGS, $alert)['type_settings'];
  }

  /**
   * Submits the given steps in order, on one alert, and returns it saved.
   *
   * Steps are keyed by step number, so a test can walk all three or only the
   * one it is about - which is what saving from an unfinished wizard does.
   */
  protected function walk(array $steps, ?Alert $alert = NULL, array $state = []): Alert {
    $form_object = $this->container->get('entity_type.manager')
      ->getFormObject('klaxon_alert', $alert === NULL ? 'add' : 'edit');
    $form_object->setEntity($alert ?? $this->container->get('entity_type.manager')
      ->getStorage('klaxon_alert')
      ->create([]));

    foreach ($steps as $step => $values) {
      $form_state = new FormState();
      $form_state->set('klaxon_step', $step);

      // Rows only exist once the Add button has made them, so a test that
      // fills three of them says so here, the way the button would have.
      foreach ($state[$step] ?? [] as $key => $value) {
        $form_state->set($key, $value);
      }

      $form_state->setValues($values + $this->stepDefaults($step));

      $this->container->get('form_builder')->submitForm($form_object, $form_state);

      $this->assertSame([], $form_state->getErrors(), sprintf('Step %d submitted without errors.', $step));
    }

    // A programmatic submission builds the entity but never reaches the save
    // handler: that one hangs off the Save button, and the form builder picks
    // a triggering element too late for its handlers to be collected. So the
    // entity the form produced is saved here instead.
    $form_object->getEntity()->save();

    return Alert::load($form_object->getEntity()->id());
  }

  /**
   * The values a step needs that its test is not interested in.
   */
  protected function stepDefaults(int $step): array {
    return match ($step) {
      self::BASICS => ['status' => TRUE, 'description' => ''],
      self::DELIVERY => [
        'channels' => [],
        'notify_on' => 'change',
        'cooldown' => 0,
        'per_row' => FALSE,
        'severity' => 'warning',
        'subject' => '',
        'body' => '',
      ],
      default => [],
    };
  }

}
