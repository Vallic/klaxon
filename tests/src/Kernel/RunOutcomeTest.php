<?php

declare(strict_types=1);

namespace Drupal\Tests\klaxon\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\klaxon\Alert\Outcome;
use Drupal\klaxon\Entity\Alert;
use Drupal\klaxon\Entity\Channel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests what running an alert by hand reports back.
 *
 * Saying nothing is the normal case and has several causes, which used to be
 * one message pointing at a log that had nothing in it: only a broken reading
 * is ever logged. Each cause now names itself.
 */
#[Group('klaxon')]
#[RunTestsInSeparateProcesses]
class RunOutcomeTest extends KernelTestBase {

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
   * A threshold that is not crossed says so, and says what it measured.
   */
  public function testNotFiringReportsTheReading(): void {
    EntityTest::create(['name' => 'one'])->save();
    $this->makeAlert('busy', ['operator' => '>', 'value' => 5]);

    $outcome = $this->runNow('busy');

    $this->assertSame(Outcome::NOT_FIRING, $outcome->reason);
    $this->assertSame(1, $outcome->reading->measure(), 'It says what it counted.');
    $this->assertFalse($outcome->spoke());
  }

  /**
   * Firing with nowhere to send is not the same as nothing to say.
   */
  public function testFiringWithNoChannelSaysSo(): void {
    EntityTest::create(['name' => 'one'])->save();
    $this->makeAlert('voiceless', ['operator' => '>', 'value' => 0], ['channels' => []]);

    $outcome = $this->runNow('voiceless');

    $this->assertSame(Outcome::NO_CHANNELS, $outcome->reason);
    $this->assertNotNull($outcome->message, 'It had something to say.');
  }

  /**
   * A second run inside the cooldown says how long is left.
   */
  public function testCooldownSaysHowLongIsLeft(): void {
    EntityTest::create(['name' => 'one'])->save();
    $this->makeAlert('quiet', ['operator' => '>', 'value' => 0], [
      'notify_on' => 'every',
      'cooldown' => 900,
    ]);

    $this->assertTrue($this->runNow('quiet')->spoke(), 'The first run speaks.');

    $outcome = $this->runNow('quiet');

    $this->assertSame(Outcome::COOLDOWN, $outcome->reason);
    $this->assertGreaterThan(0, $outcome->silentFor);
    $this->assertLessThanOrEqual(900, $outcome->silentFor);
  }

  /**
   * An alert told to speak once does not repeat itself, and says why.
   */
  public function testAlreadyFiringSaysSo(): void {
    EntityTest::create(['name' => 'one'])->save();
    $this->makeAlert('once', ['operator' => '>', 'value' => 0], ['notify_on' => 'change']);

    $this->assertTrue($this->runNow('once')->spoke());
    $this->assertSame(Outcome::ALREADY_FIRING, $this->runNow('once')->reason);
  }

  /**
   * A switched off alert is not run at all.
   */
  public function testDisabledSaysSo(): void {
    $this->makeAlert('off', ['operator' => '>', 'value' => 0], ['status' => FALSE]);

    $this->assertSame(Outcome::DISABLED, $this->runNow('off')->reason);
  }

  /**
   * An alert that cannot read is broken, and that one is in the log.
   */
  public function testUnreadableSaysSo(): void {
    $this->makeAlert('broken', ['entity_type' => 'no_such_entity_type']);

    $this->assertSame(Outcome::UNREADABLE, $this->runNow('broken')->reason);
  }

  /**
   * Runs an alert the way the Run now operation does.
   */
  protected function runNow(string $id): Outcome {
    return $this->container->get('klaxon.dispatcher')->fireNowExplained($id);
  }

  /**
   * Creates an enabled alert counting entity_test, delivering to the channel.
   */
  protected function makeAlert(string $id, array $type, array $overrides = []): void {
    // The caller's values win, so an override of NULL, [] or FALSE counts.
    Alert::create($overrides + [
      'id' => $id,
      'label' => $id,
      'status' => TRUE,
      'type' => $type + ['id' => 'entity_query', 'entity_type' => 'entity_test', 'aggregate' => 'count'],
      'channels' => ['c'],
      'notify_on' => 'every',
      'subject' => $id,
    ])->save();
  }

}
