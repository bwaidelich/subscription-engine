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
| 12 | Package constraints | The doctrine store allows `^1 \|\| ^2`, revisited when 2.0 is tagged. The dcb and neos adapters require `^2` since [Filtered reads](#filtered-reads-target-20). No adapter tag until `read()` is final | Implementations may add an optional parameter under 1.x. `ReadOptions` doesn't exist in 1.x. A released adapter would break if `read()` changes again before 2.0 |
| 13 | Local development against core | Core's `main` has the branch alias `2.x-dev`. Dependent packages set `minimum-stability: dev` and `prefer-stable: true` | composer-studio makes the local core canonical and hides Packagist. Without the alias, `dev-main` doesn't match `^1 \|\| ^2` and `composer update` fails. `prefer-stable` keeps other dependencies stable |

### Known consequences

- **Lock time scales with subscriber count.** With 10 subscriptions, `limit: 500` can mean 5,000 handler calls.
- **A laggard holds back the others.** A run reads from the lowest position of its subscriptions, so subscriptions that are ahead receive no new events until the laggard catches up. Run independently lagging subscriptions separately through `SubscriptionEngineCriteria`. The engine has no automatic handling. Concurrent subscriptions may change this.

### Open points

For the `read()` filter/query follow-up: Settled in [Filtered reads](#filtered-reads-target-20).

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
| 12 | Failed runs | An exception between a successful claim and the commit rolls back and returns `ProcessedResult::failed(0, $errors)` with the engine event `CatchUpRunFailed`. The subscription keeps its status | If the exception escaped, a worker that gets restarted would run into it again immediately, and the caller would not learn which subscription failed. Unlike `catchUpActive()`, which still rethrows: Blocking callers want it loud. Switching to an exception later would be a break |
| 13 | Retry policy | The caller's. The engine keeps no state of failed runs, so the next call claims the failed subscription again. Callers leave it out through `SubscriptionEngineCriteria` | An earlier draft set failed subscriptions aside per engine instance. That only helped processes that loop, and the worker still needed its own backoff on top, as retries otherwise follow the wake-up rate. Two layers of failure handling, and the engine would have to pick delays and clocks for everyone. Adding engine state later is additive, removing it would be a break |
| 14 | Repeating failures | Never move a subscription to `ERROR` by themselves. No failure counter in 2.0 | `ERROR` can only be left with `reset()` and a full replay. A database outage of N runs would then cost a full replay of every subscription that was running. A counter only makes sense together with a `retry()` that keeps the position |
| 15 | Failed subscriptions and `hasPendingEvents` | A failed subscription counts as pending, as it is still behind | The contract stays "calling again with the same arguments could process more". Callers that leave it out don't see it |
| 16 | Exceptions that still escape | From `beginTransaction()` and `claimNext()`, as no subscription is involved, and from listeners after the commit, as the batch is persisted | There is no subscription to report, and an unavailable database must stay visible |
| 17 | `setup()`, `reset()`, discovery | Don't skip locked rows | Discovery would treat a locked row as missing, and `add()` would fail with a duplicate key. `setup()` and `reset()` would silently miss subscriptions. See [The reset() race](#the-reset-race-target-20) for how they use locks now |
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
- **Callers have to leave failed subscriptions out.** Otherwise the next call claims the failing subscription again, which starves the others, and a loop on `while ($result->hasPendingEvents)` never ends. Single calls should use `catchUpActive()` and `boot()`.
- **Failures can go unnoticed.** Callers must check `successful()`. `SubscriberFailedToHandleEvent` and `CatchUpRunFailed` make failures visible in the logs.
- **Repeated work.** Every retry repeats the batch of the failing subscription, bounded by `limit`.
- **Statement-based replication.** MySQL considers `SKIP LOCKED` unsafe for it.

### Open points

- Escalation of repeating failures, for example a `critical` log after N failed runs: In the worker, it needs no core change. Postponed until real failure patterns are known.
- `SubscriptionEngineCriteria` that excludes ids and ignores those without a subscriber. Today callers leave subscriptions out with an include list, which fails once a subscriber is removed. Additive.
- A persisted failure counter that moves a subscription to `ERROR` after N failed runs, together with a `retry()` that keeps the position. It requires a new column and a new field on `Subscription`. Additive.
- The `read()` filter/query follow-up: Settled in [Filtered reads](#filtered-reads-target-20).

## The reset() race (target: 2.0)

### Context

`setup()` and `reset()` called `findByCriteriaForUpdate()` outside of a transaction, so the lock was released right away and workers kept running during the handlers. Both races were reproduced:

- **`reset()`:** A worker catches up during the handler. With a subscription `ACTIVE` at position 2 and 5 events, the projection ends up with `[3,4,5,1,2,3,4,5]`: Events 3 to 5 are handled twice.
- **`setup()`:** A worker commits during the handler of a `BOOTING` subscription and the stale position is written back. The projection ends up with `[1,2,3,1,2,3,4,5]`.

Holding a transaction during the handler is no option. `TRUNCATE` and DDL commit implicitly on MySQL and MariaDB, the store then fails with "The database has ended the transaction".

### Decisions

| # | Topic | Decision | Why |
|---|-------|----------|-----|
| 1 | Keeping workers out of `reset()` | A status fence: A short transaction waits for the row locks and sets `SubscriptionStatus::RESETTING`, which no catch-up matches. The handlers run without a lock, then the subscription goes to `BOOTING` at position 0 | Waiting for the lock also waits for in-flight runs. Handlers can use DDL. The store contract stays unchanged, a status is no schema change |
| 2 | Fixing `setup()` | The handlers keep running without lock and fence. The status change happens in a short transaction on a freshly read row | A fence would stop all workers during every `setup()`. Setup handlers have to stay compatible with running event handlers |
| 3 | Scope | 2.0 only, no backport to 1.x. The upgrade notes warn about 1.x | The new status could break consumers with an exhaustive `match` |
| 4 | Scope of the fence | One transaction for all matching subscriptions that have a subscriber. If a lock can't be acquired: Roll back, rethrow, nothing changed. Subscriptions without subscriber keep `SubscriberNotFound` and don't change | All workers have stopped before the first handler runs, which protects handlers that clear a table shared by several subscriptions |
| 5 | Failing reset handler | The subscription goes to `ERROR`, with an unchanged position and the status before the fence as `previousStatus`. The others continue, and the result is failed | Keeping the previous status, as before, lets workers continue on a half-cleared projection. `ERROR` is ignored by catch-ups and `setup()`, and `reset()` retries it |
| 6 | Already `RESETTING` | `reset()` does not run the handler. It dispatches `ResetAlreadyInProgress` at warning level and reports an `Error`, the others continue. `reset($criteria, force: true)` takes over | Another reset is running or one has crashed, and the engine can't tell which. Proceeding would let a second handler clear rows of a boot that the first reset has started. Any timeout based on `last_saved_at` is wrong for some long handler and depends on clocks |
| 7 | Exception escaping the final write | The exception escapes. That subscription and the remaining ones of the call stay `RESETTING` | The database may be gone. `force: true` recovers, and the handlers run again |
| 8 | Reads in `setup()` | Discovery and the main list use `findByCriteria()` | Outside of a transaction the lock had no effect, they only waited for running catch-ups |
| 9 | Transition in `setup()` | Per subscription: Lock the row if it is still `NEW`, `BOOTING` or `ACTIVE`, and decide by that fresh state. `NEW` goes to `BOOTING`. With `FROM_NOW`, `NEW` and `BOOTING` go to `ACTIVE` at `lastPosition()`. `BOOTING` with `FROM_BEGINNING` and `ACTIVE` are not written. Any other status is left alone. An exception rolls back and is rethrown | A position written by a worker can't regress, and the fence of a concurrent `reset()` isn't overwritten |
| 10 | Failing setup handler, detaching | Same short transaction: `ERROR` or `DETACHED` on the fresh row, only if it still matches | A failing handler must not write a stale position either |
| 11 | Recursion guard | `setup()` and `reset()` throw `RecursiveCatchUpException` if called during a catch-up with `ids === null` or overlapping ids. The name stays, a second class isn't worth it | On the same connection the fence wouldn't wait for the lock of the outer run and its commit wouldn't end the outer transaction. The outer run would then overwrite `RESETTING` with `ACTIVE`. A catch-up called from a reset handler needs no guard, it finds nothing because of the fence |
| 12 | Engine events | `SubscriberWasReset` reports the status from before the fence. Events of the fence are dispatched after its commit | The change from `ACTIVE` to `BOOTING` is what the log should say. A listener must not be able to roll back the fence |
| 13 | Tests | Engine tests that run a worker inside the handler, two-connection tests for lock waits, and worker processes on all databases running `reset()` with a `TRUNCATE` handler | Implicit commits and the lock behavior of a status change can't be observed in memory |

### Known consequences

- **New status value.** Code with an exhaustive `match` on `SubscriptionStatus` has to handle `RESETTING`. Stores with a string column need no change.
- **The fence can deadlock with a blocking worker on MySQL and MariaDB.** The worker holds a record of the `status` index and waits for the primary key, the fence holds the primary key and needs that index record for its `UPDATE`. Reproduced on MariaDB 11.4 in 2 of 6 test runs with 4 resets each, not on MySQL 8.4 or PostgreSQL 17. Whichever side is rolled back has changed nothing: `reset()` throws and can be repeated, a catch-up repeats its batch. Workers using `claimNext()` hold no such index record.
- **A long catch-up delays the fence.** `reset()` waits for running batches, and rows that are already locked wait with it. Bounded runs shorten that.
- **A crashed `reset()` stays visible.** The subscription stays `RESETTING` and receives no events until someone calls `reset($criteria, force: true)`. Calling that while the other reset is still running recreates the race.
- **`force: true` runs the handler again.** Reset handlers have to tolerate running twice.
- **Handlers of one call run in sequence.** All subscriptions of the call stay fenced until their handler is done.
- **Setup handlers run while workers are active.** A handler that needs exclusive access requires stopped workers.
- **Concurrent discovery can fail.** Two simultaneous `setup()` calls can both `add()` the same new subscription, the second one fails with a duplicate key. That is loud, a retry fixes it and no data is damaged. A fix requires an upsert in the store contract.

## Filtered reads (target: 2.0)

### Context

Subscribers often care about a small part of the event log, but every run reads and passes all events. The event stores can filter (DCB by event types and tags, Neos by stream and event types), the adapter contract couldn't express it.

A parameter added to `EventStoreAdapter::read()` after 2.0 would break every adapter, so the signature has to be final before 2.0 is tagged.

### Decisions

| # | Topic | Decision | Why |
|---|-------|----------|-----|
| 1 | Source of the filter | Declared per subscriber: `Subscriber::create(..., filter: ...)`. Not persisted. Changing it for an existing subscription requires a new id or `reset()` | A per call filter would let positions skip events that another call expects. Persisting a fingerprint would need a schema change and a serializable filter |
| 2 | Filter type | Opaque and adapter specific: `EventStoreAdapter` is `@template F of object = object`, the filter is `object\|null` in core | Stores differ too much: Neos streams, DCB tags. A core value object would fit none of them well. As `Subscriber` isn't generic, PHPStan can't link a filter to its adapter, a wrong type surfaces as exception of the adapter |
| 3 | Signature | Breaking: `read(Position $startPosition, ReadOptions $options): iterable` with `ReadOptions::create(limit, upTo, filter)`. `upTo` is inclusive. A `limit` below 1 and `upTo: Position::none()` throw | New options don't change the signature. `upTo: none()` would read nothing, the engine never needs it |
| 4 | Runs with one subscription | The filter is passed to `read()`, bounded by `upTo`, a snapshot of `lastPosition()` taken before the read. A read that is exhausted moves the subscription to `upTo`. If the start is beyond `upTo`, nothing is read | A filtered read doesn't show how far it scanned. Without the snapshot, a subscription without matching events would stay behind, rescan the gap on every run, and count as pending forever. A scanned-up-to position reported by the adapter would need a heavier return type and an extra query in both stores |
| 5 | Runs with several subscriptions | One unfiltered read without `upTo`. The engine applies the filters with the new required `EventStoreAdapter::matches(F $filter, E $event): bool` | Keeps the one shared read and the order of events across subscribers. No merging of filters, which Neos can't do for several streams, and no equality on opaque filters. Passing a filter that all subscriptions share to the adapter is an internal optimization that can be added later |
| 6 | Events a filter excludes | Advance the position like handled events, without engine event | A partial run makes visible progress and doesn't rescan. `SubscriberSkippedEvent` means "already ahead", a new event would be noise and can be added later |
| 7 | What `limit` counts | Events returned by `read()`: Matching events with one filtered subscription, all events otherwise | The adapter can't report how many events it scanned. `upTo` bounds the scan |
| 8 | Adapters | `read()` returns exactly the matching events. No engine fallback. Each adapter only accepts filters it can apply, in SQL or PHP | Silent fallbacks were rejected elsewhere in this project |
| 9 | `hasPendingEvents` shortcut | The shortcut of [Skip-locked catch-ups](#skip-locked-catch-ups-target-20) decision 9 only applies if there was nothing to read: No event for an unfiltered read, a start beyond `upTo` for a filtered one | A filtered subscription can read no event while it scanned a gap, so it's no proof that the others are at the end |
| 10 | Snapshot only when needed | `lastPosition()` is only called before filtered reads. `hasPendingEvents` keeps calling it after the run instead of reusing the snapshot | An unfiltered read shows how far it got. Reusing the snapshot would save one cheap query, but report events appended during the run as not pending, which delays workers that wait for notifications |
| 11 | Adapter filter types | DCB: `Query`. Neos: `NeosEventFilter` with a stream name and an optional `EventStreamFilter` | The native query types of the stores, Neos can only read one stream |

### Known consequences

- **No I/O savings for runs with several subscriptions.** `catchUpActive()` and `boot()` without criteria read all events. Filtered subscriptions save I/O in `catchUpNextActive()`, `bootNext()` and runs restricted to one subscription.
- **A sparse filter can scan far.** `limit` counts matching events, so a run can scan up to the end of the event log before it finds enough.
- **One more query per filtered run.** The snapshot of `lastPosition()`.
- **Changing a filter is not detected.** Events that the old filter excluded are not handled. Use a new id or `reset()`.
- **Events appended after the snapshot** are read by the next run. A subscription stays `BOOTING` until a run reaches the end.
