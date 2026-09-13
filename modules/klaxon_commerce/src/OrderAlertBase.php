<?php

declare(strict_types=1);

namespace Drupal\klaxon_commerce;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\klaxon\Plugin\Klaxon\AlertType\EntityQuery;
use Drupal\state_machine\WorkflowManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shared shape for every alert about orders.
 *
 * The entity type, the aggregate and the date field are decided by the
 * subclass, so what is left on the form is what a shop manager can actually
 * answer: which orders, in which states, in which shop.
 *
 * States are read from the workflow of each order type rather than hardcoded.
 * A stock Commerce site has draft, completed and canceled; a real one has a
 * dozen states nobody but that shop has ever heard of, and an alert that only
 * knew the stock three would be useless there.
 */
abstract class OrderAlertBase extends EntityQuery {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    TimeInterface $time,
    EntityTypeBundleInfoInterface $bundle_info,
    protected readonly WorkflowManagerInterface $workflowManager,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $time, $bundle_info);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('datetime.time'),
      $container->get('entity_type.bundle.info'),
      $container->get('plugin.manager.workflow'),
      $container->get('entity_field.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'entity_type' => 'commerce_order',
      'order_types' => [],
      'states' => [],
      'store_id' => '',
      'currency' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * Whether this alert is about carts rather than placed orders.
   *
   * A shop's cart table is mostly abandoned drafts. Counting them as orders
   * would make every number wrong, so they are excluded unless the whole point
   * of the alert is the carts.
   */
  protected function isAboutCarts(): bool {
    return FALSE;
  }

  /**
   * TRUE when this site can tell a cart from an order.
   *
   * The cart flag belongs to Commerce Cart, not to the order module. Without
   * it there are no carts to exclude, so the distinction quietly stops
   * mattering rather than becoming a broken query.
   */
  protected function hasCartField(): bool {
    return isset($this->entityFieldManager->getFieldStorageDefinitions('commerce_order')['cart']);
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);
    // The entity type is not a question here, and neither is the aggregate.
    unset($form['measure']);
    $form['orders'] = $this->ordersForm();
    $form['orders']['#weight'] = -3;

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    // The parent rebuilds the configuration from the sections it owns, so the
    // order-shaped values are merged on top of what it settled on.
    parent::submitConfigurationForm($form, $form_state);

    $orders = (array) $form_state->getValue('orders');

    $this->setConfiguration([
      'order_types' => array_values(array_filter((array) ($orders['order_types'] ?? []))),
      'states' => array_values(array_filter((array) ($orders['states'] ?? []))),
      'store_id' => (string) ($orders['store_id'] ?? ''),
      'currency' => (string) ($orders['currency'] ?? ''),
    ] + $this->getConfiguration());
  }

  /**
   * Which orders to look at.
   */
  protected function ordersForm(): array {
    $form = [
      '#type' => 'details',
      '#title' => $this->t('Which orders'),
      '#open' => TRUE,
    ];

    $form['order_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Order types'),
      '#options' => $this->orderTypeOptions(),
      '#default_value' => (array) ($this->configuration['order_types'] ?? []),
      '#description' => $this->t('Leave them all clear for all of them.'),
    ];

    $form['states'] = [
      '#type' => 'select',
      '#title' => $this->t('States'),
      '#multiple' => TRUE,
      '#options' => $this->stateOptions(),
      '#default_value' => (array) ($this->configuration['states'] ?? []),
      '#description' => $this->t('Grouped by the workflow that defines them. Leave empty for any state.'),
    ];

    $form['store_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Store'),
      '#options' => $this->storeOptions(),
      '#default_value' => $this->configuration['store_id'] ?? '',
    ];

    $form['currency'] = [
      '#type' => 'select',
      '#title' => $this->t('Currency'),
      '#options' => $this->currencyOptions(),
      '#default_value' => $this->configuration['currency'] ?? '',
      '#description' => $this->t('Totals from different currencies must not be added together, so a total needs one picked.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function query(string $entity_type_id, bool $aggregate = FALSE) {
    $query = parent::query($entity_type_id, $aggregate);

    $order_types = array_filter((array) ($this->configuration['order_types'] ?? []));
    if ($order_types !== []) {
      $query->condition('type', array_values($order_types), 'IN');
    }

    $states = array_filter((array) ($this->configuration['states'] ?? []));
    if ($states !== []) {
      $query->condition('state', array_values($states), 'IN');
    }

    if (($store = (string) ($this->configuration['store_id'] ?? '')) !== '') {
      $query->condition('store_id', $store);
    }

    if (($currency = (string) ($this->configuration['currency'] ?? '')) !== '') {
      $query->condition('total_price.currency_code', $currency);
    }

    if ($this->hasCartField()) {
      $query->condition('cart', $this->isAboutCarts());
    }

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): array {
    return ['module' => ['commerce_order']];
  }

  /**
   * Order type labels, keyed by machine name.
   */
  protected function orderTypeOptions(): array {
    $options = [];

    foreach ($this->entityTypeManager->getStorage('commerce_order_type')->loadMultiple() as $id => $type) {
      $options[(string) $id] = (string) $type->label();
    }

    natcasesort($options);

    return $options;
  }

  /**
   * Every order state on the site, grouped by the workflow defining it.
   */
  protected function stateOptions(): array {
    $options = [];

    foreach ($this->workflowManager->getDefinitions() as $definition) {
      if (($definition['group'] ?? '') !== 'commerce_order') {
        continue;
      }

      $group = (string) ($definition['label'] ?? $definition['id']);

      foreach (array_keys((array) ($definition['states'] ?? [])) as $state) {
        // States are keyed by machine name and shared across workflows, so a
        // state picked here matches wherever it appears. The group is only
        // there to make a list of forty states navigable.
        $options[$group][$state] = (string) ($definition['states'][$state]['label'] ?? $state);
      }
    }

    ksort($options);

    return $options;
  }

  /**
   * The stores, with an "any" option first.
   */
  protected function storeOptions(): array {
    $options = ['' => (string) new TranslatableMarkup('- Any -')];

    foreach ($this->entityTypeManager->getStorage('commerce_store')->loadMultiple() as $id => $store) {
      $options[(string) $id] = (string) $store->label();
    }

    return $options;
  }

  /**
   * The currencies in use, with an "any" option first.
   */
  protected function currencyOptions(): array {
    $options = ['' => (string) new TranslatableMarkup('- Any -')];

    foreach ($this->entityTypeManager->getStorage('commerce_currency')->loadMultiple() as $id => $currency) {
      $options[(string) $id] = sprintf('%s (%s)', (string) $currency->label(), (string) $id);
    }

    return $options;
  }

  /**
   * The order types in words, for a summary line.
   */
  protected function orderTypeSummary(): string {
    $types = array_filter((array) ($this->configuration['order_types'] ?? []));

    return $types === []
      ? (string) new TranslatableMarkup('orders')
      : implode('/', $types) . ' ' . (string) new TranslatableMarkup('orders');
  }

}
