# Klaxon System

A submodule of [Klaxon](../../README.md). Enable it alongside the
main module; it adds alert types and nothing else.

Three alerts about the site rather than about its content: cron falling behind,
errors piling up in the log, queues that stopped draining. All three watch
things that fail silently — where nothing looks broken, no page throws, and the
first sign of trouble is a person asking why they never got their email.

## The alerts

**Cron is falling behind** (`system_cron`) — fires when the last *completed*
cron run is older than N hours.

Read the limitation before relying on this one. Klaxon evaluates scheduled
alerts on cron, so an alert about cron can only speak while cron is running. If
cron dies completely this says nothing, and nothing evaluated by cron could.
Catching that needs something outside the site, watching the site.

What it does catch is the failure that happens more often and is far harder to
notice: cron that runs but never finishes. Drupal stamps `system.cron_last` at
the *end* of a successful run, so a hook that fatals halfway leaves that
timestamp standing still while cron appears, from the outside, to be firing
perfectly happily. It also catches cron running far less often than whoever set
it up believes. Set the threshold comfortably above the intended interval: on
hourly cron, six hours means five runs were missed or died before finishing.

**Errors in the log** (`system_errors`) — counts entries at a chosen severity
or worse, in a chosen set of channels, over the last N minutes, and fires when
there are more than expected. Nobody reads the log; that is not a criticism of
anyone, it is what logs are — a place to look once you already know something
is wrong. This turns it around.

A count rather than each entry, because the interesting signal is almost always
volume: one PHP notice is Tuesday, four hundred in ten minutes is a deployment
that needs rolling back. The message names the busiest channels and renders the
most recent entry with its placeholders filled in. Narrowing to one channel is
what makes a low threshold meaningful — two errors from `payment` is worth
hearing about, two from `php` is not.

Needs the Database Logging module. Without it the alert takes itself off the
list rather than offering a query that cannot run.

**Queue backlog** (`system_queue`) — fires when a queue holds more items than
it should, and names the ones that are behind.

Queues fail quietly by design: items go in, nothing takes them out, and the
site carries on looking well. The mail nobody received and the search index
nobody reindexed both look like this. The threshold is per queue rather than
across all of them, because that is the question worth asking — a thousand
items spread over twenty queues is a busy site, a thousand in one is a worker
that died. Queues are listed worst first, so a message truncated by a chat
service still leads with the one worth looking at.

Covers core queues, which is every queue with a `QueueWorker` plugin. Queues
belonging to Advanced Queue are entities of their own and are not included.

## A note on thresholds

Each of these asks its question in the units the question comes in — hours,
minutes, items — and derives the threshold from that. The derivation happens
wherever the configuration came from, not only on form submit, so an alert
created by a config import or by code tests what it says it tests rather than
quietly falling back to the default.


## Maintainers

- [Valentino Međimorec](https://www.drupal.org/u/valic)
