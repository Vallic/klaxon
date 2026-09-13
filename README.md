# Klaxon

![Klaxon](https://www.drupal.org/files/project-images/klaxon.png)

Build a business alert in the UI: pick what to watch, and where to shout about
it.


## Introduction

Klaxon turns "somebody should have noticed that" into something the site says
out loud. An alert is one question worth asking — how many orders were placed
in the last half hour, has anything been sitting in this state since yesterday,
did that queue stop draining — and a place to say the answer.

Contrib has plenty of modules that hard-wire one source to one channel, and two
frameworks either side of the target: Monitoring exposes sensors for an external
monitoring product to scrape, ECA automates a site through a graphical modeler.
Neither lets someone say "email me the daily sales total" or "tell the ops
channel when nothing has sold for half an hour" without a developer writing a
cron hook. Klaxon is that missing middle.

It is not a monitoring system and does not try to be. Monitoring watches the
machine; this watches the business running on it, and the two rarely fail at
the same time.


## Requirements

- Drupal 11.4 or later
- No other modules. Slack, Telegram, Discord and the generic webhook all go
  through core's HTTP client


## Installation

Install as you would any Drupal module. See
[Installing modules](https://www.drupal.org/docs/extending-drupal/installing-modules).

Enable a submodule alongside it for ready-made alerts:

- **Klaxon Commerce** for a shop
- **Klaxon System** for the site itself


## Configuration

Everything lives under Configuration, in the Klaxon section, across four
tabs: **Dashboard**, **Alerts**, **Channels** and **Settings**.

1. Add a **channel** first. An alert with nowhere to go still runs and still
   records its state, it just says nothing.
2. Add an **alert**, which asks what kind of alert it is — grouped by category,
   so the shop ones sit together — and then only what that kind needs.
3. Grant permissions. *Administer Klaxon alerts* is for the people writing
   them; *Administer Klaxon channels* is restricted separately, because a
   channel holds credentials.

Every alert row has a **Run now** operation. It evaluates immediately and tells
you whether it fired, which is the fastest way to find out that a window is
wrong or a threshold never trips.

### The Dashboard

The lists answer "what alerts exist" and "what channels exist". The dashboard
answers the two questions neither does: is anything wrong right now, and if
something goes wrong later, will anybody actually hear about it.

It leads with what is **firing**, then with what is **worth fixing** — and the
first thing in that list is the delivery queue, because a queue nothing is
draining silences every alert on the site while each of them still looks
perfectly well configured. After that come the quiet failures: an alert with no
channel, an alert pointing at a channel that is switched off or gone.

Then the **routing**: one card per channel showing every alert that will be
sent down it, and a card called *Nowhere* for the alerts pointing at nothing,
because those are the ones this page exists to catch.

Last, **every alert and what it last did** — its kind, whether it is firing,
when it was last checked, when it last fired, how many times, and a Run now
link. The same information `drush klaxon:list` prints, for the people who do
not have a terminal open.


## Concepts

Two config entities:

- **Alert** — one thing worth knowing about. Holds an alert type and its
  settings, a message template, and the channels it delivers to.
- **Channel** — a place to deliver, and the credentials to get there. Alerts
  reference channels, never copy them, so one workspace connection serves every
  alert and an exported alert can never carry a secret.

And two plugin types:

| Type | Question | Ships with |
|---|---|---|
| Alert type | what is worth knowing | `entity_query`, `entity_event`, `code` |
| Transport | where to say it | `slack`, `telegram`, `discord`, `mail`, `webhook`, `log` |

An **alert type** answers, in one form, every question there is: when to look,
what to look at, and what makes it worth saying. Three shipped types cover the
shapes those questions come in.

- **`entity_query`** — check on a schedule how many things there are, what they
  add up to, or which ones they are, and fire when that number crosses a line.
- **`entity_event`** — fire the moment something is created, changed or deleted,
  optionally only when a field takes a particular value.
- **`code`** — fire when code says so, and say whatever the code handed over.

Every alert type returns a `Reading`: an optional scalar, rows keyed by a stable
identifier, and some context. Nothing downstream knows what produced it, so a
new alert type never touches a transport and a new transport never has to know
what it is describing.

## Where it can shout

Six transports, all in the module rather than in a submodule each, because
none of them needs a library — Slack, Telegram and Discord are a JSON POST at a
URL, which core's HTTP client already does.

| Transport | Wants | Notes |
|---|---|---|
| `slack` | An incoming webhook URL | A webhook URL already names its channel, so one Klaxon channel maps to one Slack channel. Severity becomes the color stripe. |
| `telegram` | A bot token and a chat ID | The bot must be in the chat, and an administrator of it if it is a channel. |
| `discord` | A channel webhook URL | Severity becomes the embed color. |
| `mail` | Addresses | One mail each, so a bad address cannot take the list down with it. |
| `webhook` | A URL, and headers | Anything else with an HTTP endpoint. |
| `log` | Nothing | The right channel to add while you are still deciding whether an alert fires too often. |

**Credentials live in channel configuration**, which means they are exported
with the site. Keep them out of it by overriding the value in `settings.php`,
which config entities respect like any other config:

```php
$config['klaxon.channel.ops_slack']['transport']['webhook_url'] = getenv('SLACK_WEBHOOK');
```

**The generic webhook** posts a documented, stable JSON shape — subject, body,
severity, facts, url, sent — rather than something shaped like one service's
API, on the grounds that the receiver is the end you can change. Headers are
where authentication goes, one `Name: value` per line. Give it a signing secret
and each request also carries `X-Klaxon-Timestamp` and an `X-Klaxon-Signature`
holding an HMAC-SHA256 of `timestamp . '.' . body`, so a receiver can tell a
real alert from anyone who guessed the URL, and reject a replay while it is at
it.

Anything that needs a payload shaped its own way wants its own transport, which
is about thirty lines on top of `HttpTransportBase`: build the payload, and
override `failure()` if the service hides its rate limit somewhere new. Two of
the three shipped ones do. Give it a `category` and the channel form groups it
with its neighbors, the same way alert types are grouped.

## Running it

Klaxon needs no command to work. Cron evaluates the scheduled alerts, entity
saves evaluate the ones watching content, and core drains the delivery queue in
the same cron run — `invokeCronHandlers()` comes before `processQueues()`, so an
alert that fires during a run is delivered in that run.

The commands are for the question cron cannot answer: why did this alert not
say anything.

| Command | What it is for |
|---|---|
| `drush klaxon:list` | Every alert, with whether it is currently firing and when it last did |
| `drush klaxon:run <id>` | Evaluate one and say what it decided. `--deliver=queue` to take the same path cron would |
| `drush klaxon:due` | Everything due, without running the rest of cron. `--deliver=now` to send inline and see the outcome |
| `drush klaxon:deliver` | Work the delivery queue now |
| `drush klaxon:test <channel>` | Send a throwaway message down one channel, to find out whether the credentials work |

**If alerts stop arriving, look at the queue first.** A backlog there means
nothing is draining it — cron not running, or a worker wedged — and that one
failure silences every alert on the site while every alert still looks
perfectly well configured. `klaxon:list` says so when it sees a backlog, and
`klaxon:deliver` empties it by hand using the same worker cron uses, so retries
and rate limits behave exactly as they would have.

## How an alert gets evaluated

Nobody configures "this one runs on cron". It follows from what the alert type
implements, which is what keeps the form down to one question:

| The type implements | Evaluated by |
|---|---|
| `ScheduledAlertInterface` | cron, when `isDue()` says so |
| `EntityEventAlertInterface` | entity insert, update and delete |
| neither | only `klaxon.dispatcher->fire()` |

## The blank for developers

Not everything worth alerting on can be described in a form. The **code** alert
type hands control back without giving up templating, the cooldown, channels
the admin UI. Nothing evaluates it: cron skips it and no entity change reaches
it. It is a configured message with a destination, aimed by code:

```php
\Drupal::service('klaxon.dispatcher')->fire('nightly_backup', [
  'rows' => [
    'srv-1' => ['label' => 'srv-1: disk full'],
    'srv-7' => ['label' => 'srv-7: timed out'],
  ],
  'facts' => ['Run' => 'nightly', 'Duration' => '41s'],
]);
```

Rows are keyed by a stable identifier, which is what lets the alert report each
one only once. Facts are listed under the message.

For anything you want to reuse, write an alert type instead. Drop a class in
`Plugin/Klaxon/AlertType` with the `Drupal\klaxon\Attribute\AlertType`
attribute, extending `ScheduledAlertBase` if cron should evaluate it — that
brings the schedule and the threshold with it and leaves you writing `read()`
— and give it a `category` — the picker groups by it, and a flat list
stops being readable about as soon as a second submodule is installed. Extending `EntityQuery` and prefilling its configuration is how a
"daily sales" or "orders stuck in a state" alert gets written in about forty
lines: fix the entity type, the aggregate and the window, and leave the person
configuring it only the questions they can answer.

## Things worth knowing

**The dead man's switch.** The `empty` operator on a scheduled query fires when
a query that should return something returns nothing. No orders in the last half
hour is usually the most valuable alert a shop can have, and it is the one
nobody writes, because the logic reads backwards.

**Report each row once.** Turn on `per_row` and the alert remembers which rows
it has already reported, in a ledger keyed by whatever identifier the reading
gave them. "This subscription renews within the hour" then announces each one
once rather than on every cron run for the rest of that hour.

**Say it once, not every ten minutes.** `notify_on` takes `every`, `change` or
`change_and_recovery`. Combined with `cooldown`, that is what stops a threshold
sitting just over the line from filling a channel.

**Bundles are picked, not typed.** Both entity-shaped alert types read the
bundles of whichever entity type is chosen and offer them as a list, reloading
when the entity type changes. A mistyped machine name is not an error — it is a
condition that never matches — so an alert that silently watches nothing is the
one failure worth designing out. An entity type with no bundles is not asked
about them.

**Time windows reach forwards.** The scheduled query accepts anything
`strtotime` understands, relative to now. `-24 hours` to `now` is what happened
yesterday; `now` to `+1 hour` is what is about to happen.

**Delivery is queued.** Cron and entity saves hand the message to a queue rather
than to a chat API, so a customer's order never fails to save because Slack is
having a bad afternoon, and retries come for free. The Run now button delivers
inline, because someone is watching.

Jobs travel as plain data, never as objects. A queue backend is under no
obligation to preserve PHP objects: the core database queue serializes and they
survive, while RabbitMQ and several others encode as JSON and they do not. A
message is only ever scalars, so this costs nothing and works everywhere.

## Example

```yaml
# klaxon.channel.ops_slack.yml
id: ops_slack
label: 'Ops'
transport:
  id: mail
  recipients: 'ops@example.com'

# klaxon.alert.quiet_shop.yml
id: quiet_shop
label: 'No orders in the last 30 minutes'
type:
  id: entity_query
  interval: 600
  entity_type: commerce_order
  bundle: regular
  date_field: placed
  window_from: '-30 minutes'
  window_to: 'now'
  aggregate: count
  operator: empty
channels:
  - ops_slack
notify_on: change_and_recovery
cooldown: 1800
severity: critical
subject: 'No orders in 30 minutes'
```

## Submodules

**Klaxon Commerce** turns the base module from a form into a list of things a
shop already wants to know: the takings, nothing selling, orders that stopped
moving, carts left behind. Four alert types, each a prefilled `EntityQuery`
subclass. See `modules/klaxon_commerce/README.md`.

**Klaxon System** watches the site rather than its content: cron falling
behind, errors piling up in the log, queues that stopped draining. Three alert
types on `ScheduledAlertBase`, which is the half of `EntityQuery` that has
nothing to do with entities — a schedule and a threshold. See
`modules/klaxon_system/README.md`.

## Not built yet

- **A content submodule**: unpublished past its date, moderation stuck.
- **Microsoft Teams**, which wants an Adaptive Card rather than anything the
  other three would recognize.
- **Key module support**, so credentials can come from somewhere that is not
  configuration at all.
- **The Views alert type**, for anything needing joins or relationships.
- **The SQL alert type**, gated behind a read-only database connection, a
  restricted permission, and a setting that makes production accept only what
  arrived through config import.


## Related projects

**[Druker](https://www.drupal.org/project/druker)** schedules Drush commands
from a process outside the site, and it closes a real gap here. Klaxon
evaluates its scheduled alerts on cron, which means a cron run that stops
takes the alerting down with it — silently, and including the alert that would
have told you cron had stopped. Give `klaxon:due` and `klaxon:deliver` a Druker
job and they keep speaking when `drush cron` does not.

That is the honest limit of the `system_cron` alert in Klaxon System: nothing
evaluated by cron can report that cron is dead. Something outside it has to.


## Maintainers

- [Valentino Međimorec](https://www.drupal.org/u/valic)
