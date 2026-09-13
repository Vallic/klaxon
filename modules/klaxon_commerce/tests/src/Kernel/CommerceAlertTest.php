<?php

declare(strict_types=1);

namespace Drupal\Tests\klaxon_commerce\Kernel;

use Drupal\Tests\commerce_order\Kernel\OrderKernelTestBase;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_price\Price;
use Drupal\klaxon\Alert\Deliverer;
use Drupal\klaxon\Entity\Alert;
use Drupal\klaxon\Entity\Channel;
use Drupal\klaxon\Reading;
use Drupal\klaxon_test\Plugin\Klaxon\Transport\Failing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers the shop alerts against real orders.
 */
#[Group('klaxon')]
#[RunTestsInSeparateProcesses]
class CommerceAlertTest extends OrderKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['commerce_cart', 'klaxon', 'klaxon_test', 'klaxon_commerce'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('klaxon', ['klaxon_state', 'klaxon_ledger']);
    $this->installConfig(['klaxon']);

    $this->container->get('commerce_price.currency_importer')->import('EUR');

    // A workflow with a state nothing moves an order out of on its own, which
    // is what a stuck order actually is. The stock workflow only has draft,
    // and draft orders are refreshed and re-saved on load.
    OrderType::create([
      'id' => 'validated',
      'label' => 'Validated',
      'workflow' => 'order_default_validation',
    ])->save();

    Channel::create([
      'id' => 'c',
      'label' => 'Test channel',
      'status' => TRUE,
      'transport' => ['id' => 'failing', 'mode' => 'ok'],
    ])->save();
  }

  /**
   * The takings are summed in one currency and reported as money.
   */
  public function testSalesTotal(): void {
    $this->makeOrder('completed', new Price('40.00', 'USD'), placed: '-2 hours');
    $this->makeOrder('completed', new Price('60.50', 'USD'), placed: '-6 hours');
    // Outside the window, so it must not be counted.
    $this->makeOrder('completed', new Price('999.00', 'USD'), placed: '-40 hours');

    $this->makeAlert('takings', [
      'id' => 'commerce_sales',
      'currency' => 'USD',
      'window_from' => '-24 hours',
      'window_to' => 'now',
    ]);

    $this->assertSame(100.5, $this->reading('takings')->value);

    $message = $this->evaluate('takings');
    $this->assertNotNull($message, 'The digest always has something to say.');
    $this->assertSame('$100.50', $message->facts['Total'], 'The money is formatted, not dumped.');
  }

  /**
   * A total below its target fires; the same total above it stays quiet.
   */
  public function testSalesTarget(): void {
    $this->makeOrder('completed', new Price('40.00', 'USD'), placed: '-2 hours');

    $this->makeAlert('target', [
      'id' => 'commerce_sales',
      'currency' => 'USD',
      'operator' => '<',
      'value' => 100,
    ]);

    $this->assertNotNull($this->evaluate('target'), 'Forty is short of a hundred.');

    $this->makeOrder('completed', new Price('80.00', 'USD'), placed: '-1 hour');
    $this->assertNull($this->evaluate('target'), 'A hundred and twenty is not.');
  }

  /**
   * Carts and other currencies stay out of the takings.
   */
  public function testSalesIgnoresCartsAndOtherCurrencies(): void {
    $this->makeOrder('completed', new Price('40.00', 'USD'), placed: '-1 hour');
    $this->makeOrder('completed', new Price('500.00', 'EUR'), placed: '-1 hour');
    $this->makeOrder('draft', new Price('900.00', 'USD'), placed: '-1 hour', cart: TRUE);

    $this->makeAlert('takings', ['id' => 'commerce_sales', 'currency' => 'USD']);

    $this->assertSame(40.0, $this->reading('takings')->value);
  }

  /**
   * Silence is the alarm, and a sale ends it.
   */
  public function testQuietShop(): void {
    $this->makeAlert('quiet', ['id' => 'commerce_quiet', 'minutes' => 45]);

    $this->assertNotNull($this->evaluate('quiet'), 'Nothing sold at all.');

    $this->makeOrder('completed', new Price('10.00', 'USD'), placed: '-90 minutes');
    $this->assertNotNull($this->evaluate('quiet'), 'An hour and a half ago is still silence.');

    $this->makeOrder('completed', new Price('10.00', 'USD'), placed: '-5 minutes');
    $this->assertNull($this->evaluate('quiet'), 'A recent sale ends it.');
  }

  /**
   * Only orders that stopped moving are named, and they are named.
   */
  public function testStuckOrders(): void {
    $stale = $this->makeOrder('validation', new Price('10.00', 'USD'), changed: '-10 hours', type: 'validated');
    $this->makeOrder('validation', new Price('10.00', 'USD'), changed: '-1 hour', type: 'validated');
    $this->makeOrder('completed', new Price('10.00', 'USD'), changed: '-10 hours', type: 'validated');

    $this->makeAlert('stuck', [
      'id' => 'commerce_stuck_orders',
      'states' => ['validation'],
      'hours' => 4,
    ]);

    // PHP turns numeric array keys back into integers, so compare as strings:
    // the ledger that deduplicates rows does the same thing.
    $this->assertSame(
      [(string) $stale->id()],
      array_map('strval', array_keys($this->reading('stuck')->rows)),
      'Only the stale one, and it is named.',
    );
    $this->assertNotNull($this->evaluate('stuck'));
  }

  /**
   * Empty carts are sessions, not abandonment.
   */
  public function testAbandonedCartsNeedContents(): void {
    $this->makeOrder('draft', new Price('10.00', 'USD'), changed: '-5 hours', cart: TRUE);
    $this->makeOrder('draft', new Price('0', 'USD'), changed: '-5 hours', cart: TRUE, empty: TRUE);
    // Too recent: this shopper may still be shopping.
    $this->makeOrder('draft', new Price('10.00', 'USD'), changed: '-10 minutes', cart: TRUE);

    $this->makeAlert('carts', [
      'id' => 'commerce_abandoned_carts',
      'hours' => 2,
      'window_from' => '-24 hours',
      'operator' => '>',
      'value' => 0,
    ]);

    $this->assertSame(1, $this->reading('carts')->value, 'One cart with something in it.');
    $this->assertNotNull($this->evaluate('carts'));
  }

  /**
   * Placed orders are not carts, whatever state they are in.
   */
  public function testAbandonedCartsIgnorePlacedOrders(): void {
    $this->makeOrder('completed', new Price('10.00', 'USD'), changed: '-5 hours');

    $this->makeAlert('carts', ['id' => 'commerce_abandoned_carts', 'hours' => 2]);

    $this->assertSame(0, $this->reading('carts')->value);
  }

  /**
   * The windows are derived wherever the configuration came from.
   *
   * Config import and code both create alerts without going anywhere near a
   * form, so a window derived only on form submit would leave those alerts
   * quietly querying the default period instead of the one they asked for.
   */
  public function testDerivedWindowsDoNotDependOnTheForm(): void {
    $this->makeAlert('quiet', ['id' => 'commerce_quiet', 'minutes' => 15]);
    $this->makeAlert('stuck', ['id' => 'commerce_stuck_orders', 'states' => ['validation'], 'hours' => 9]);
    $this->makeAlert('carts', ['id' => 'commerce_abandoned_carts', 'hours' => 7]);

    $this->assertSame('-15 minutes', $this->typeConfig('quiet')['window_from']);
    $this->assertSame('-9 hours', $this->typeConfig('stuck')['window_to']);
    $this->assertSame('-7 hours', $this->typeConfig('carts')['window_to']);
  }

  /**
   * The stored configuration of one alert's type.
   */
  protected function typeConfig(string $id): array {
    return Alert::load($id)->getType()->getConfiguration();
  }

  /**
   * Every shipped type saves, reloads and describes itself.
   */
  public function testEveryTypeRoundTrips(): void {
    $types = [
      'commerce_sales' => ['currency' => 'USD'],
      'commerce_quiet' => ['minutes' => 15],
      'commerce_stuck_orders' => ['states' => ['validation'], 'hours' => 6],
      'commerce_abandoned_carts' => ['hours' => 3],
    ];

    foreach ($types as $id => $config) {
      $this->makeAlert($id, ['id' => $id] + $config);
      $type = Alert::load($id)->getType();

      $this->assertSame($id, $type->getPluginId());
      $this->assertNotSame('', $type->summary());
      $this->assertSame('commerce_order', $type->getConfiguration()['entity_type']);
    }
  }

  /**
   * Creates an order, backdating it where the test needs one.
   */
  protected function makeOrder(string $state, Price $total, ?string $placed = NULL, ?string $changed = NULL, bool $cart = FALSE, bool $empty = FALSE, string $type = 'default'): Order {
    $order = Order::create([
      'type' => $type,
      'state' => $state,
      'store_id' => $this->store->id(),
      'mail' => 'buyer@example.com',
      'cart' => $cart,
    ]);

    // The total is recalculated from the items on every save, so it has to be
    // made of items rather than written directly.
    if (!$empty) {
      $order_item = $this->container->get('entity_type.manager')
        ->getStorage('commerce_order_item')
        ->create([
          'type' => 'test',
          'quantity' => 1,
          'unit_price' => $total,
        ]);
      $order_item->save();
      $order->addItem($order_item);
    }

    if ($placed !== NULL) {
      $order->set('placed', strtotime($placed));
    }

    $order->save();

    // Written straight to the table on purpose. Order refresh re-saves draft
    // orders and stamps the changed time as it goes, so a cart can only be
    // aged from underneath it.
    if ($changed !== NULL) {
      $this->container->get('database')->update('commerce_order')
        ->fields(['changed' => strtotime($changed)])
        ->condition('order_id', $order->id())
        ->execute();
      $this->container->get('entity_type.manager')->getStorage('commerce_order')->resetCache();
    }

    return $order;
  }

  /**
   * Creates an enabled alert of one type, delivering to the test channel.
   */
  protected function makeAlert(string $id, array $type): void {
    Alert::create([
      'id' => $id,
      'label' => $id,
      'status' => TRUE,
      'type' => $type,
      'channels' => ['c'],
      'notify_on' => 'every',
      'subject' => $id,
    ])->save();
  }

  /**
   * Evaluates one alert and returns whatever it decided to say.
   */
  protected function evaluate(string $id) {
    $this->container->get('state')->set(Failing::DELIVERED, []);
    $this->container->get('queue')->get(Deliverer::QUEUE)->deleteQueue();

    return $this->container->get('klaxon.runner')->run(Alert::load($id));
  }

  /**
   * What one alert measures, without the notification policy in the way.
   */
  protected function reading(string $id): Reading {
    return Alert::load($id)->getType()->read();
  }

}
