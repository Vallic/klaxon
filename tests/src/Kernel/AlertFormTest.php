<?php

declare(strict_types=1);

namespace Drupal\Tests\klaxon\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\entity_test\EntityTestHelper;
use Drupal\klaxon\Entity\Alert;
use Drupal\klaxon\Entity\Channel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers the alert form: picking a type, and what that stores.
 *
 * The picker is the one place where a plugin's settings cross from a form into
 * configuration, and the nesting it travels through is invisible until it
 * breaks. Everything here is about that crossing.
 */
#[Group('klaxon')]
#[RunTestsInSeparateProcesses]
class AlertFormTest extends KernelTestBase {

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
    $options = $this->buildForm()['type']['type_id']['#options'];

    $this->assertArrayHasKey('General', $options, 'The shipped types are grouped.');
    $this->assertArrayHasKey('entity_query', $options['General']);
    $this->assertArrayHasKey('entity_event', $options['General']);
    $this->assertArrayHasKey('code', $options['General']);
  }

  /**
   * The chosen type explains itself, and does so inside the replaced region.
   */
  public function testTheChosenTypeExplainsItself(): void {
    $form = $this->buildForm();

    $this->assertSame('select', $form['type']['type_id']['#type']);
    $this->assertNotEmpty($form['type']['chosen']['description']['#value']);
    $this->assertArrayHasKey('type_settings', $form['type']['chosen']);

    // The explanation is a sibling of the settings, not a member of them:
    // a plugin is free to have a setting of its own called "description".
    $this->assertArrayNotHasKey('description', $form['type']['chosen']['type_settings']);
  }

  /**
   * A submitted form reaches configuration with the settings intact.
   */
  public function testSubmittingStoresTheTypeSettings(): void {
    EntityTestHelper::createBundle('article', 'Article', 'entity_test');

    $this->submitForm([
      'label' => 'No new accounts today',
      'id' => 'quiet_accounts',
      'type_id' => 'entity_query',
      'type_settings' => [
        'schedule' => ['interval' => 600, 'cron' => ''],
        'measure' => [
          'entity_type' => 'entity_test',
          'bundles_wrapper' => ['bundle' => 'article'],
          'aggregate' => 'count',
          'sum_field' => '',
          'limit' => 200,
          'date_field' => 'created',
          'date_storage' => 'timestamp',
          'window_from' => '-24 hours',
          'window_to' => 'now',
        ],
        'fire' => ['operator' => 'empty', 'value' => 0],
      ],
      'channels' => ['c' => 'c'],
      'severity' => 'critical',
      'cooldown' => 1800,
    ]);

    $alert = Alert::load('quiet_accounts');

    $this->assertNotNull($alert, 'The alert was created.');
    $this->assertSame('entity_query', $alert->getTypeId());
    $this->assertSame(['c'], $alert->getChannelIds());
    $this->assertSame(1800, $alert->getCooldown());
    $this->assertSame('critical', $alert->getSeverity());

    $config = $alert->getType()->getConfiguration();

    // The three form sections are flattened back out on the way in, so the
    // stored shape does not mirror the form's nesting.
    $this->assertSame('entity_test', $config['entity_type']);
    $this->assertSame('article', $config['bundle'], 'The bundle came out of its AJAX container.');
    $this->assertSame('created', $config['date_field']);
    $this->assertSame('-24 hours', $config['window_from']);
    $this->assertSame('empty', $config['operator']);
    $this->assertSame(600, $config['interval']);
    $this->assertArrayNotHasKey('measure', $config, 'No form scaffolding leaked into config.');
    $this->assertArrayNotHasKey('bundles_wrapper', $config);
    $this->assertArrayNotHasKey('description', $config);
  }

  /**
   * Switching type stores the new type, not a merge of both.
   */
  public function testSwitchingTypeDiscardsTheOldSettings(): void {
    $this->submitForm([
      'label' => 'Was a query',
      'id' => 'switcher',
      'type_id' => 'entity_query',
      'type_settings' => [
        'schedule' => ['interval' => 600, 'cron' => ''],
        'measure' => [
          'entity_type' => 'entity_test',
          'bundles_wrapper' => ['bundle' => ''],
          'aggregate' => 'count',
          'sum_field' => '',
          'limit' => 200,
          'date_field' => 'created',
          'date_storage' => 'timestamp',
          'window_from' => '-24 hours',
          'window_to' => 'now',
        ],
        'fire' => ['operator' => 'empty', 'value' => 0],
      ],
      'channels' => ['c' => 'c'],
    ]);

    $this->submitForm([
      'label' => 'Now fired by code',
      'id' => 'switcher',
      'type_id' => 'code',
      'type_settings' => [],
      'channels' => ['c' => 'c'],
    ], Alert::load('switcher'));

    $config = Alert::load('switcher')->getType()->getConfiguration();

    $this->assertSame('code', $config['id']);
    $this->assertArrayNotHasKey('window_from', $config, 'Nothing from the query survived.');
    $this->assertArrayNotHasKey('entity_type', $config);
  }

  /**
   * A bundled entity type offers its real bundles, not a text field.
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
   * Changing the entity type reloads the bundles rather than the whole form.
   */
  public function testTheEntityTypeSelectReloadsJustTheBundles(): void {
    $settings = $this->settingsFor([
      'id' => 'entity_event',
      'entity_type' => 'entity_test',
      'operations' => ['insert'],
    ]);

    $this->assertSame(
      $settings['bundles_wrapper']['#attributes']['id'],
      $settings['entity_type']['#ajax']['wrapper'],
      'The select replaces the container next to it.',
    );
  }

  /**
   * The built settings subform for an alert of the given type.
   */
  protected function settingsFor(array $type): array {
    $alert = $this->container->get('entity_type.manager')
      ->getStorage('klaxon_alert')
      ->create(['id' => 'probe', 'label' => 'Probe', 'type' => $type]);
    $alert->save();

    $form = $this->container->get('entity.form_builder')->getForm($alert, 'edit');

    return $form['type']['chosen']['type_settings'];
  }

  /**
   * Builds the add form the way the route does.
   */
  protected function buildForm(): array {
    $alert = $this->container->get('entity_type.manager')
      ->getStorage('klaxon_alert')
      ->create([]);

    return $this->container->get('entity.form_builder')->getForm($alert, 'add');
  }

  /**
   * Submits the alert form with the given values on top of the defaults.
   */
  protected function submitForm(array $values, ?Alert $alert = NULL): void {
    $form_object = $this->container->get('entity_type.manager')
      ->getFormObject('klaxon_alert', $alert === NULL ? 'add' : 'edit');
    $form_object->setEntity($alert ?? $this->container->get('entity_type.manager')
      ->getStorage('klaxon_alert')
      ->create([]));

    $form_state = new FormState();
    $form_state->setValues($values + [
      'status' => TRUE,
      'description' => '',
      'notify_on' => 'change',
      'cooldown' => 0,
      'per_row' => FALSE,
      'severity' => 'warning',
      'subject' => '',
      'body' => '',
    ]);

    $this->container->get('form_builder')->submitForm($form_object, $form_state);

    $this->assertSame([], $form_state->getErrors(), 'The form submitted without errors.');

    // A programmatic submission builds the entity but never reaches the save
    // handler: that one hangs off the Save button, and the form builder picks
    // a triggering element too late for its handlers to be collected. So the
    // entity the form produced is saved here instead.
    $form_object->getEntity()->save();
  }

}
