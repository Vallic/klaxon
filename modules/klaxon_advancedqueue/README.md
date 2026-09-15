# Klaxon Advanced Queue

Alerts about [Advanced Queue](https://www.drupal.org/project/advancedqueue):
jobs piling up, jobs failing, jobs stuck mid-flight.

## Why this is separate from the queue backlog alert

`klaxon_system` already watches core queues, and a core queue has one number:
how many items are waiting. Advanced Queue keeps a job *after* it has run,
with a state, and each state going wrong means something different:

| State | What a rising count means |
|---|---|
| Waiting | Nothing is running the worker, or it cannot keep up |
| Being processed | A job was claimed and the process that claimed it died. It is now neither done nor waiting, and nothing will pick it up |
| Failed | The worker runs and is wrong. Nothing retries these on their own, so the pile only grows |
| Succeeded | Normal. Worth watching only if you never prune |

So the state is the question, not an afterthought.

## The alert

**Advanced Queue jobs by state** — fires when a queue holds more jobs in the
chosen states than it should.

- **Job states** — ticked together, they are counted together.
- **Complain when a queue holds more than** — counted **per queue**, not
  across all of them. Five hundred jobs spread over twenty queues is a busy
  site; five hundred in one is a broken one. Zero means any job at all in
  those states is worth hearing about, which is what you want for failures.
- **Queues** — leave empty to watch every queue, including ones added later.

Disabled queues are skipped: nothing is meant to be draining them. A queue
named in the alert and since deleted is skipped rather than fataling, so
housekeeping does not stop the alert about the queues that remain.

The message names each queue that is over, worst first, with the count broken
down by state — so a message truncated by a chat service still leads with the
queue most worth looking at.

## Two alerts, not one

Failures want a much lower threshold than a backlog, so they are better as two:

- *Backlog* — Waiting, more than a few hundred, checked every half hour.
- *Failures* — Failed, more than zero, checked often.

## Requirements

- [Klaxon](https://www.drupal.org/project/klaxon)
- [Advanced Queue](https://www.drupal.org/project/advancedqueue)
