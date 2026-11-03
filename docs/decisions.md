# Decisions

## Bounded catch-ups (target: 2.0)

### Context

`SubscriptionEngine::catchUpSubscriptions()` holds one transaction and the row locks of all matching subscriptions while it reads to the end of the event log.
With a large backlog that means one huge transaction and a long-held lock, which blocks fair rotation between workers.

### Decisions

| # | Topic | Decision | Why |
|---|-------|----------|-----|
| 1 | API shape | Optional `int\|null $limit = null` as second parameter of `catchUpActive()` and `boot()`. Values below 1 throw `InvalidArgumentException` | A criteria option would also reach `setup()`, `reset()` and `getSubscriptions()`, where a limit is meaningless. A new method doubles the API for one integer |
| 2 | Name | `limit`, not `batchSize` | One call processes one batch. `batchSize` suggests the engine continues with further batches |
| 3 | Default | `null` keeps today's behavior: one transaction, read to the end of the log. A bounded call runs one batch and returns. There is no internal loop | Returning control to the caller is what allows rotation between workers |
| 4 | What the limit counts | Events read, including events skipped by subscriptions that are already ahead | Matches `numberOfProcessedEvents` and bounds I/O. Progress is guaranteed: the lowest-position subscription handles every event it reads or drops out with an error. Handler calls would be ambiguous, and a skip-only batch would be unbounded |
| 5 | Reporting pending events | `ProcessedResult::$hasPendingEvents` (`bool`), always `false` when unbounded | Explicit and additive |
| 6 | Detecting pending events | Read `limit + 1` events and leave the extra one unprocessed | No extra query, and a backlog of exactly `limit` events finishes in one run |
| 7 | Meaning of pending | "Calling again with the same arguments could process more". `false` if every subscription of the run failed, even if the log has more events | `while ($result->hasPendingEvents)` always terminates. Callers check `successful()` separately. The rest of a batch is still read after a total failure, so that behavior is unchanged |
| 8 | Adapter contract | Breaking: `EventStoreAdapter::read(Position $startPosition, int\|null $limit = null): iterable`. Adapters return at most `$limit` events in order. No sub-interface and no fallback | Accepted for 2.0. The engine still stops at the limit, because the peek needs that check anyway |
| 9 | BOOTING to ACTIVE | A partial boot persists positions and stays `BOOTING`. All subscriptions of a run activate only once no pending event remains. No `SubscriptionActivated` on partial runs | `catchUpActive()` ignores `BOOTING`, so partial boots stay isolated. An event appended between read and commit is picked up by the next `catchUpActive()`, as before |
| 10 | Engine events | `CatchUpFinished` fires on every run and gains `bool $hasPendingEvents = false`. `__toString()` appends `, more events pending` only when `true`. No new event classes | Existing log output stays identical |
| 11 | Scope of the break | `read()` is the only breaking change of this work | Everything else is additive |
| 12 | Package constraints | dcb and neos adapters and the doctrine store allow `^1 \|\| ^2`, revisited when 2.0 is tagged. No adapter tag until `read()` is final | Implementations may add an optional parameter under 1.x. A released adapter would break if `read()` changes again before 2.0 |
| 13 | Local development against core | Core's `main` has the branch alias `2.x-dev`. Dependent packages set `minimum-stability: dev` and `prefer-stable: true` | composer-studio makes the local core canonical and hides Packagist. Without the alias, `dev-main` doesn't match `^1 \|\| ^2` and `composer update` fails. `prefer-stable` keeps other dependencies stable |

### Known consequences

- **Lock time scales with subscriber count.** With 10 subscriptions, `limit: 500` can mean 5,000 handler calls.
- **A laggard holds back the others.** A run reads from the lowest position of its subscriptions, so subscriptions that are ahead receive no new events until the laggard catches up. Run independently lagging subscriptions separately through `SubscriptionEngineCriteria`. The engine has no automatic handling. Concurrent subscriptions may change this.

### Open points

For the `read()` filter/query follow-up:

- `limit` would count matching events only, so it no longer bounds how much the store scans.
- Positions advance only to the last handled event. A filtered subscription could stay far behind the log and rescan the same gap on every run, and a partial run could make no visible progress.
- `limit + 1` still works, but "pending" would mean more *matching* events.

For concurrent subscriptions:

- They may revisit the one-lock-per-run model and the advice on per-subscription runs.

## Escaping exceptions roll back the run (target: 2.0)

### Context

`SubscriptionStore` had no rollback, so an exception that escaped a catch-up left its transaction open.
On MySQL and MariaDB this is easy to reach: Workers booting concurrently deadlock.
With DBAL, the `commit()` of later runs only decremented the nesting level. Workers that caught the exception and kept going reported processed events that were not persisted, and subscriptions ended in `ERROR`.

### Decisions

| # | Topic | Decision | Why |
|---|-------|----------|-----|
| 1 | Store contract | Breaking: `SubscriptionStore::rollback(): void` | Only the store can end its transaction |
| 2 | Escaping exception before the commit | Roll back and rethrow the original exception. A failed rollback is ignored | Repeating the run is safe with the same engine instance. The original exception says what went wrong, a failed rollback usually only means that the connection is gone |
| 3 | Escaping exception after the commit | No rollback | The batch is persisted. This covers listeners of `CatchUpFinished` |
| 4 | `processingSubscriptions` | Restored on every exit path with `finally` | This was rejected before, because it would have hidden the open transaction. With the rollback there is nothing left to hide |
| 5 | Isolation level | `DoctrineSubscriptionStore` uses `READ COMMITTED` for its transactions on MySQL and MariaDB. It only applies to the next transaction, and not if the connection is already in one | With `REPEATABLE READ`, `SELECT ... FOR UPDATE` takes next-key locks on the `status` index. Moving a row within that index then deadlocks with waiting workers |

### Known consequences

- Subscriber handlers that share the connection also run with `READ COMMITTED` in these transactions
- A transaction that was started outside of the store keeps its isolation level

### Open points

- A database error inside a subscriber is handled like any other subscriber failure: The subscription goes to `ERROR` and the run continues. If the database has rolled back the transaction because of that error (deadlock on MySQL), the rest of the run is not part of the transaction that the handlers assume
- On PostgreSQL an SQL error aborts the transaction, so the following `update()` fails and escapes
