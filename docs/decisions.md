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

## A store fails if the database ended its transaction (target: 2.0)

### Context

The engine treats every exception of a subscriber as a failure of that subscriber: The subscription goes to `ERROR` and the run continues.
MySQL and MariaDB roll back the whole transaction of a deadlock victim. A deadlock in a handler therefore discarded the events of the current batch, while all following writes were committed right away, including the positions. With two subscriptions and a deadlock at event 10, the failing one ended at position 9 with no rows, and the other one stayed `ACTIVE` with events 1 to 9 missing.

### Decisions

| # | Topic | Decision | Why |
|---|-------|----------|-----|
| 1 | Where to detect it | `SubscriptionStore::add()` and `update()` throw if the database has ended the transaction | The engine knows nothing about the database. The first write after the failure happens before any other subscriber handles another event, so nothing is written outside of the transaction. The contract needs no new method |
| 2 | Outcome | The exception escapes, the run is rolled back and no subscription goes to `ERROR` | A deadlock is temporary, the next catch-up repeats the batch |
| 3 | Detection in `DoctrineSubscriptionStore` | `SELECT 1` on MySQL and MariaDB, followed by `PDO::inTransaction()` | The response to the failed statement has no transaction status, so `inTransaction()` still reports `true` until the next successful statement |

### Known consequences

- One additional query per write of the store on MySQL and MariaDB
- `DoctrineSubscriptionStore` can only detect this with PDO drivers, not with `mysqli`
- A handler that catches the database exception itself and continues writes outside of the transaction until the next write of the store. Those writes are repeated after the rollback
- An SQL error that keeps occurring on PostgreSQL never leads to `ERROR` and blocks all subscriptions of the run. Nothing is lost, and per-subscription runs isolate it
- Damage from earlier versions can't be detected. It requires a `reset()` and a replay of the affected subscriptions

## Skip-locked catch-ups (target: 2.0)

### Context

Several workers should be able to call the engine on the same set of subscriptions without advisory locks or external partition assignment. Each one catches up what it could lock and skips the rest.

`findByCriteriaForUpdate()` blocks on locked rows, and a run locks all of its subscriptions. Simply adding `SKIP LOCKED` to that query would not help: The first worker would lock everything, and the others would find nothing until it commits.

### Decisions

| # | Topic | Decision | Why |
|---|-------|----------|-----|
| 1 | Claim size | One subscription per call, not configurable | Claiming all gives workers turns, but no parallelism. It also keeps the laggard problem. N > 1 brings that problem back for one more parameter |
| 2 | Opt-in | Blocking stays the default | Synchronous callers rely on "once this returns, my events are processed" |
| 3 | Engine API | New methods `catchUpNextActive($criteria, $limit)` and `bootNext($criteria, $limit)` | The behavior differs, not just a value. A flag would hide that the call handles only one subscription |
| 4 | Store contract | Breaking: `SubscriptionStore::claimNext(SubscriptionCriteria $criteria): Subscription\|null` skips locked rows, returns the lowest position at the time of the read with ties broken by id, locks nothing else, and holds the lock until commit or rollback | A flag on `SubscriptionCriteria` would be meaningless for `findByCriteria()`. An optional interface would silently lose the feature. Both would let stores silently block |
| 5 | Ordering | Lowest position first, ties by id | Lag first is the same order, as all subscriptions read the same log. With `ORDER BY id`, workers would keep claiming a caught-up subscription while another one lags |
| 6 | Claim query in `DoctrineSubscriptionStore` | Two steps: Read the candidate ids without locks, ordered by position and id. Then try them one by one with `WHERE id = ? AND <criteria> FOR UPDATE SKIP LOCKED`. No schema change | `ORDER BY position, id LIMIT 1 FOR UPDATE SKIP LOCKED` locks all matching rows on MySQL and MariaDB, as they lock before sorting. An index on `(status, position)` prevents that, but makes blocking catch-ups deadlock on MySQL: The `SELECT` holds the index record and waits for the primary key, an `UPDATE` of `position` holds the primary key and waits for the index record. `READ COMMITTED` does not help. MariaDB also ignored that index if the criteria contained ids, which would have required `FORCE INDEX`. Verified on MySQL 8.4, MariaDB 11.4 and PostgreSQL 17 |
| 7 | Everything locked | Success with 0 events, `ProcessedResult::$allSubscriptionsLocked` and the engine event `AllSubscriptionsLocked`. Detected with `findByCriteria()` after an empty claim | "All busy" and "nothing there" call for different reactions. The check can race with a released lock, which is harmless: The next call claims that row |
| 8 | `hasPendingEvents` | Same contract as before: "Calling again with the same arguments could process more", across all matching subscriptions. An active subscription is pending if it is behind `lastPosition()`, a booting one if it exists at all | With only the claimed subscription in mind, `while ($result->hasPendingEvents)` would stop while others lag. A booting subscription at the end of the log still has to be activated |
| 9 | Computing it | No queries if a claimed active subscription had no events: It was the lowest one, so all unlocked ones are at the end of the log. Otherwise `findByCriteria()` and `lastPosition()` | Idle polling stays cheap. As the order is read without locks, a lagging subscription can rarely be missed, see below. Locked subscriptions have stale positions and may report `true`, the next call then claims something else or reports them as locked |
| 10 | Booting | A claimed subscription activates once its own read reached the end of the log | A lagging subscription must not keep the others in `BOOTING`. Consistent with decision 9 of bounded catch-ups, as a run now contains one subscription |
| 11 | Detached subscriptions | Mark as `DETACHED` and claim again in the same transaction | A removed subscriber often has a low position. Returning right after detaching it would look like an idle run |
| 12 | Failed runs | An exception between a successful claim and the commit rolls back and returns `ProcessedResult::failed(0, $errors)` with the engine event `CatchUpRunFailed`. The subscription keeps its status | With lowest-first, a subscription whose run keeps throwing would be claimed first forever. On PostgreSQL every repeating SQL error of a handler does that. If the exception escaped, a worker that gets restarted would run into it again immediately. Unlike `catchUpActive()`, which still rethrows |
| 13 | Setting subscriptions aside | The engine instance leaves a failed subscription out of its next claims, by narrowing `ids` to the known subscribers minus the failed ones. Tracked per status | No store contract change. Per status, because a worker alternating between `bootNext()` and `catchUpNextActive()` would otherwise clear the list with every idle boot call |
| 14 | Retrying them | The list is cleared for the criteria of a call, if the claim found nothing or a claimed active subscription had no events, or if nothing else is left to claim | The rest has no work, so the failed subscription gets one retry per cycle and a temporary error recovers by itself. `ERROR` is no option: It requires a `reset()` and a full replay |
| 15 | Set-aside and `hasPendingEvents` | Set-aside subscriptions don't count as pending | Otherwise `while ($result->hasPendingEvents)` would never end |
| 16 | Exceptions that still escape | From `beginTransaction()` and `claimNext()`, as no subscription is involved, and from listeners after the commit, as the batch is persisted | There is nothing to set aside, and an unavailable database must stay visible |
| 17 | `setup()`, `reset()`, discovery | Keep blocking | Discovery would treat a locked row as missing, and `add()` would fail with a duplicate key. `setup()` and `reset()` would silently miss subscriptions |
| 18 | Recursion guard | Unchanged, also for the new methods | A nested call on the same connection sees the lock of the outer run as free. The guard throws for `ids === null` and overlapping ids, so `claimNext()` can't return a subscription that is being processed |
| 19 | Processed subscription on the result | Not part of `ProcessedResult` | `CatchUpStarted` and `CatchUpFinished` already carry it, and no caller logic needs it |
| 20 | SQLite | Serial: `LIMIT 1` with the same ordering, without `FOR UPDATE` | `BEGIN EXCLUSIVE` already makes a second worker wait before it claims anything. Each event is still handled once, only the parallelism is lost |
| 21 | MySQL < 8.0, MariaDB < 10.6 | DBAL's `NotSupported` escapes | The caller explicitly asked for non-blocking behavior. A silent fallback would turn a worker pool into a queue without any visible cause |
| 22 | DBAL constraint | `^3.8 \|\| ^4` in `wwwision/subscription-engine-doctrine` | `forUpdate()` and `ConflictResolutionMode` exist from 3.8 on. The previous `^3.3` was already wrong, because `forUpdate()` doesn't exist below 3.8 |

### Known consequences

- **The claim order is not exact.** Another worker can commit between the two steps of a claim, so it may not be the lowest position anymore. Events of a subscription stay in order and are handled exactly once, as the locked read returns the current position and rechecks the criteria. In that race the shortcut of decision 9 can report no pending events while another subscription lags. A loop on `hasPendingEvents` can then stop early, and the next run picks it up.
- **More queries.** A claim takes one query for the candidates, and one per candidate it tries.
- **No order between subscribers.** Blocking runs call all subscribers per event. With claims, one subscription can be ahead of another, so a handler must not rely on the projection of another subscriber.
- **More reads of the log.** N subscriptions take N reads of the event log, instead of one that they share.
- **A short-lived process has no memory.** Set-aside subscriptions are tracked per engine instance. A process that makes a single call and exits runs into a failing subscription every time, and starves the others. Workers have to loop within the process.
- **Failures can go unnoticed.** Callers must check `successful()`. `SubscriberFailedToHandleEvent` and `CatchUpRunFailed` make failures visible in the logs.
- **Repeated work.** Every retry repeats the batch of the failing subscription, bounded by `limit`.
- **Statement-based replication.** MySQL considers `SKIP LOCKED` unsafe for it.

### Open points

- `setup()` and `reset()` call `findByCriteriaForUpdate()` outside of a transaction, so the lock is released immediately and a worker can catch up while a `reset()` handler is running. This race already exists, but always-running workers make it more likely. A transaction is no simple fix, as DDL in handlers commits implicitly on MySQL. Until then: Stop workers before `reset()`.
- A persisted failure counter that moves a subscription to `ERROR` after N failed runs would also cover short-lived processes. It requires a new column, a new field on `Subscription`, and a choice of N. It can be added later without conflicting with the set-aside.
- The `read()` filter/query follow-up: With filtered subscriptions, a position can stay behind `lastPosition()` although no matching event is left, so `hasPendingEvents` would stay `true`.
