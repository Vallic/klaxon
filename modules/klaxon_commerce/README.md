# Klaxon Commerce

A submodule of [Klaxon](../../README.md). Enable it alongside the
main module; it adds alert types and nothing else.

Four alerts a shop already wanted, so nobody has to describe them in a generic
form: what was taken, whether anything is selling, which orders stopped moving,
and how many carts are being left behind.

Each one is a subclass of Klaxon's `entity_query` alert type with the entity,
the aggregate and the date field already decided. What is left on the form is
what a shop manager can answer.

## The alerts

**Sales total** (`commerce_sales`) — adds up `total_price` over a period. Left
alone it is a digest: at seven every morning, what yesterday came to. Change the
test to "is below" and a number, and it becomes the alert that says the day is
going badly while there is still time to care. A currency is required, because
adding totals across currencies produces a number that means nothing.

**Nothing has sold** (`commerce_quiet`) — fires when no order has been placed
for N minutes. The dead man's switch, and usually the most valuable alert a shop
can have: a broken checkout, an expired payment credential and a failed deploy
all look identical from the outside, and all look like this. Set the silence
above the longest gap on your quietest night, or it will cry wolf every Sunday
at four in the morning.

**Orders stuck in a state** (`commerce_stuck_orders`) — lists orders that have
sat in a state longer than they should have, counted from the last time the
order changed at all rather than from when it was placed. A state is required:
every order is permanently "stuck" in completed, so an alert without one would
report the whole shop. Pair it with "report each match only once" and each order
is named when it goes stale rather than every hour until someone acts.

**Carts left behind** (`commerce_abandoned_carts`) — counts carts that have
something in them and have not been touched for a while. The count is the
interesting reading, not the individual carts: abandonment is normal, and a jump
in how much of it there is usually means checkout broke rather than that
shoppers all changed their minds at once. Empty carts are sessions, not
abandonment, so they are excluded.

## Two things it gets right that a generic form cannot

**States come from the workflows on the site.** A stock Commerce install has
draft, completed and canceled. A real shop has a dozen states nobody else has
heard of — `awaiting_capture`, `held_for_review`, `part_shipped` — and an alert
that only knew the stock three would be useless there. The state list is
read from every `commerce_order` workflow and grouped by the workflow that
defines it.

**Carts are excluded from everything except the cart alert.** A shop's order
table is mostly abandoned drafts. Counting them as orders makes every number
wrong. The cart flag belongs to Commerce Cart rather than Commerce Order, so on
a site without it there are no carts to exclude, the distinction quietly stops
mattering, and the abandoned-cart alert takes itself off the list rather than
offering a query that cannot run.

## Writing another one

The four here are the pattern. Prefill `defaultConfiguration()`, drop the form
sections you have already answered, and override `query()` if there is a
condition the form cannot express:

```php
#[AlertType(id: 'commerce_big_refunds', label: new TranslatableMarkup('Large refunds'))]
class BigRefunds extends OrderAlertBase {

  public function defaultConfiguration(): array {
    return [
      'aggregate' => self::ROWS,
      'date_field' => 'changed',
      'window_from' => '-24 hours',
      'window_to' => 'now',
      'operator' => 'not_empty',
    ] + parent::defaultConfiguration();
  }

}
```

## Not built yet

- **Payments.** Refunds over an amount, and authorizations that were never
  captured. Both want `commerce_payment` rather than `commerce_order`, so they
  need a base of their own rather than extending `OrderAlertBase`.
- **Stock**, once there is a stock module worth depending on.


## Maintainers

- [Valentino Međimorec](https://www.drupal.org/u/valic)
