<?php

declare(strict_types=1);

namespace Drupal\Tests\klaxon_advancedqueue\Kernel;

use Drupal\advancedqueue\Entity\Queue;
use Drupal\advancedqueue\Job;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers the Advanced Queue alert: counting jobs by the state they are in.
 */
#[Group('klaxon')]
#[RunTestsInSeparateProcesses]
class QueueJobsAlertTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'advancedqueue',
    'advancedqueue_test',
    'klaxon',
    'klaxon_advancedqueue',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installSchema('advancedqueue', ['advancedqueue']);
    $this->installSchema('klaxon', ['klaxon_state', 'klaxon_ledger']);
    $this->installConfig(['klaxon']);

    foreach (['slow' => 'Slow', 'fast' => 'Fast'] as $id => $label) {
      Queue::create([
        'id' => $id,
        'label' => $label,
        'backend' => 'database',
        'status' => TRUE,
      ])->save();
    }
  }

  /**
   * A queue under the threshold says nothing; over it, it is named.
   */
  public function testTheThresholdIsPerQueue(): void {
    $this->enqueue('slow', 5);

    $this->assertSame([], $this->firing(['limit' => 5]), 'Five is not more than five.');
    $this->assertSame(['slow'], $this->firing(['limit' => 4]), 'Six would be, and so is the fifth over four.');
  }

  /**
   * Only the queues asked for are counted.
   */
  public function testOnlyTheChosenQueuesAreWatched(): void {
    $this->enqueue('slow', 3);
    $this->enqueue('fast', 3);

    $this->assertSame(['fast', 'slow'], $this->firing(['limit' => 0], TRUE));
    $this->assertSame(['slow'], $this->firing(['limit' => 0, 'queues' => ['slow']]));
  }

  /**
   * The state is the question: the same jobs count or not by their state.
   */
  public function testJobsAreCountedByState(): void {
    $this->enqueue('slow', 2, Job::STATE_FAILURE);
    $this->enqueue('slow', 1);

    $this->assertSame(['slow'], $this->firing(['limit' => 1, 'states' => [Job::STATE_FAILURE]]));
    $this->assertSame([], $this->firing(['limit' => 1, 'states' => [Job::STATE_QUEUED]]), 'One waiting is not over one.');

    // Both states together clear a threshold neither reaches alone.
    $this->assertSame(['slow'], $this->firing(['limit' => 2, 'states' => [Job::STATE_QUEUED, Job::STATE_FAILURE]]));
  }

  /**
   * A queue named in the alert and since deleted is skipped, not fatal.
   *
   * Housekeeping should not stop an alert about the queues that remain.
   */
  public function testDeletedQueueDoesNotBreakTheAlert(): void {
    $this->enqueue('slow', 3);

    $this->assertSame(
      ['slow'],
      $this->firing(['limit' => 0, 'queues' => ['slow', 'gone_last_week']]),
    );
  }

  /**
   * A disabled queue is not watched: nothing is meant to be draining it.
   */
  public function testDisabledQueueIsLeftAlone(): void {
    $this->enqueue('slow', 3);

    $queue = Queue::load('slow');
    $queue->setStatus(FALSE);
    $queue->save();

    $this->assertSame([], $this->firing(['limit' => 0]));
  }

  /**
   * The message names the queue and breaks the count down by state.
   */
  public function testTheMessageSaysWhichQueueAndWhat(): void {
    $this->enqueue('slow', 4, Job::STATE_FAILURE);

    $reading = $this->read(['limit' => 0, 'states' => [Job::STATE_FAILURE]]);
    $row = $reading->rows['slow'];

    $this->assertStringContainsString('Slow', $row['label']);
    $this->assertStringContainsString('4 Failed', $row['label']);
    $this->assertSame('Failed', $reading->context['States']);
  }

  /**
   * Puts jobs on a queue in the given state.
   */
  protected function enqueue(string $queue_id, int $count, string $state = Job::STATE_QUEUED): void {
    $queue = Queue::load($queue_id);

    for ($i = 0; $i < $count; $i++) {
      $job = Job::create('simple', ['n' => $i]);
      $queue->enqueueJob($job);

      if ($state !== Job::STATE_QUEUED) {
        $job->setState($state);
        $queue->getBackend()->onSuccess($job);
      }
    }
  }

  /**
   * The reading for an alert configured this way.
   */
  protected function read(array $configuration): object {
    return $this->container->get('plugin.manager.klaxon_alert_type')
      ->createInstance('advancedqueue_jobs', $configuration)
      ->read();
  }

  /**
   * The ids of the queues the alert would name, sorted for comparison.
   */
  protected function firing(array $configuration, bool $sort = FALSE): array {
    $ids = array_keys($this->read($configuration)->rows);

    if ($sort) {
      sort($ids);
    }

    return $ids;
  }

}
