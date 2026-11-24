# Step 1.4 — `SyncEventEntity` transitions + `SyncRetryRules` (Lead)

**Status:** DONE_WITH_NOTES

**Summary.** Implemented the full retry lifecycle on `SyncEventEntity` — `enqueue()`, `claim()`,
`markSucceeded()`, `scheduleRetry()`, `releaseStaleLock()`, `isExhausted()`, `markDead()`, plus
`jobClass()` and `hasStaleLease()` — and the `SyncRetryRules` constants and pure helpers they read.
Every transition returns a NEW instance, takes `$now` and jitter as arguments, and refuses an illegal
move by throwing `SyncEventTransitionException` with a named factory. Two real defects were found by
the self-check and fixed (D-042, D-043), both around the same theme: the attempt counter and the lease.
104 assertions pass.

**Inputs used.**
- Plan `docs/ai-plans/lead-module-action-plan.md` §5 Step 1.4, plus A6, A1, S1, S2, DP-7, DP-3.
- `database/migrations/2026_11_22_210100_create_sync_events_table.php` — `attempts` as
  `unsignedTinyInteger`, `next_attempt_at` NOT NULL, `locked_at` as the lease.
- `core/CrmSync/Domain/ValueObjects/SanitizedErrorMessage.php` (Step 1.3) and the two enums.
- Rules 01 P6, 05 R1 (items 2–6) + R3, 06 T8, 08 E3/E8; AGENTS §5, §7, §13.

**Rules and skills applied.** AGENTS §1, §5, §7 (rich domain: every transition rule in the entity),
§11, §13; rules 01, 05, 06, 08. Tests paused per AGENTS §6.

---

## Pre-flight

- **CONTEXT HYDRATION.** Read the last three `DECISIONS.md` entries (D-039, D-040, D-041), the latest
  `PROJECT-LOG.md` entry (`Lead/1.3`), and the previous step's report (`27-crmsync-value-objects.md`).
  The developer's message approved D-040, D-041, `fromCanonical()`, non-throwing sanitization and the
  4000-char cap. **D-041** bound this step: the scrub-before-truncate rule means `markDead()` receives
  an already-scrubbed `SanitizedErrorMessage` and stores its value. Step 1.3's checksum caveat stands.
  **D-034** stays OPEN and untouched.
- **GIT TRACKING GUARD.** `git check-ignore -v .claude/rules .claude/skills docs/ai-reports` printed
  nothing for any of the three (exit 1). **PASS** — no HALT.

## Files

**Modified — 1 file** (Step 0.2 skeleton).

| File | Change |
| --- | --- |
| `core/CrmSync/Domain/Entities/SyncEventEntity.php` | Added `enqueue()`, `claim()`, `markSucceeded()`, `scheduleRetry()`, `releaseStaleLock()`, `isExhausted()`, `hasStaleLease()`, `markDead()`, `jobClass()`; private `with()`, `assertProcessing()`, `KEEP`. Constructor unchanged (14 params). |

**Modified — 1 file** (Step 0.2 skeleton).

| File | Change |
| --- | --- |
| `core/CrmSync/Domain/Rules/SyncRetryRules.php` | Implemented the constants (`MAX_ATTEMPTS`, `BACKOFF_BASE_SECONDS`, `BACKOFF_CAP_SECONDS`, `LEASE_TIMEOUT_SECONDS`, `BATCH_SIZE`) and the pure `backoffSeconds()`/`mayRetry()`. |

**Created — 1 file** (the exception the step names).

| File | Contents |
| --- | --- |
| `core/CrmSync/Domain/Exceptions/SyncEventTransitionException.php` | Factories `notClaimable()`, `notProcessing()`, `exhausted()`, `invalidJitter()`. |

**Not touched:** the VOs, the other Entities, the enums, Infrastructure, every other module,
`framework/`, `vendor/`, `database/`. No events scaffolded (`08` E3 — none raised yet).

## The transition rules

| Transition | From | To | Rule |
| --- | --- | --- | --- |
| `enqueue()` | — | `pending` | `next_attempt_at = $now`, `attempts = 0`. |
| `claim()` | `pending` | `processing` | Sets `locked_at`. Refuses any other state. Does **not** increment `attempts`. |
| `markSucceeded()` | `processing` | `succeeded` | Clears the lease, sets `processed_at`, clears `last_error`. |
| `scheduleRetry()` | `processing` | `pending` | `attempts + 1`; due at `backoff(attempts) + jitter`. Refuses a negative jitter and an exhausted event. |
| `releaseStaleLock()` | `processing` | `pending` | `attempts + 1`; due immediately. Refuses an exhausted event. |
| `markDead()` | `processing` | `dead` | Clears the lease, sets `processed_at` and `last_error` from the VO. |

## Design decisions

**The attempt counter increments on the failure, not on the claim.** An attempt has happened when it
has been made and failed. Incrementing on `claim()` would spend the budget for a worker that crashed
before calling the CRM, so a crash loop would exhaust the retries without ever having tried.

**`releaseStaleLock()` increments too — that is the point of S1.** The reaper's reclaim is the one
increment that runs without the worker that owned the event, and the abandoned attempt may well have
reached the CRM. Counting it is what stops a repeatedly crashing worker from retrying forever. S1's
"kept far below 255" exists precisely because this increment sits outside the ordinary path — see the
defect below.

**`hasStaleLease()` treats the boundary as expired.** A lease held for exactly `LEASE_TIMEOUT_SECONDS`
has run out; leaving the boundary to `>` would make a lease that expired at `t` still live at `t`, and
the reaper's scan would have an undecidable edge.

**Jitter arrives as a parameter** (DP-7), so the entity stays deterministic and testable while the
worker still spreads a retry storm.

## Self-check

| Check | Result | Evidence |
| --- | --- | --- |
| C1 files exist, no unexpected file | PASS | 2 edited in place, 1 exception created (named by the step). |
| C2 scope audit vs brief | PASS | Only the entity, the rules class and the transition exception. mtimes 20:17–20:20; `framework/`/`vendor/` clean. |
| C3 `php -l` per file | PASS | All 18 files under `core/CrmSync/Domain`. |
| C4 `composer dump-autoload` | PASS | "…containing 1956 classes" (was 1955; +1 exception). |
| C5 rule DoDs walked | PASS | 05 R1/R3, 08 E3/E8, 06 T8 walked below. |
| C6 06 T8 greps | T8 A **zero**, T8 B **zero** | Both exit 1. |
| C7 immutability | PASS | `readonly=true`, `final=false`; every transition returns a new instance; receiver unchanged after each (asserted). |
| C8 no clock / random / I-O | PASS | No `new DateTimeImmutable`/`date(`/`time()`/`random_*`/`mt_rand`/`wpdb`/`file_*` in either file (grep exit 1). `$now` and jitter are parameters. |
| C9 illegal transitions refused | PASS | Every wrong-state move throws with a named factory: claim on processing/succeeded/dead; succeed/retry/release/dead on pending or a terminal state. |
| C10 attempts can never pass the ceiling | PASS | Both incrementing transitions check `mayRetry()`. Verified by a 400-iteration reaper loop (stops at the ceiling), by starting points `0,1,2,3,4,5,250,254` (max observed 254, never 256), and by the full retry walk (`MAX_ATTEMPTS - 1` retries then refusal). |
| C11 `backoffSeconds()` pure and bounded | PASS | Exponential from the base, monotonic, capped; `attempts = 1_000_000` returns the cap without overflow; a negative input returns the base rather than raising a shift error; same input → same output. |
| C12 lease staleness | PASS | Fresh and just-before-timeout leases are not stale; at and past the timeout are; `pending`/`succeeded` never are. |
| C13 no hardcoded user-facing text | PASS | Only exception messages and `TRANSLATION_PREFIX` keys; no `__()`. |
| C14 no events | PASS | No `domainEvents` property, no `releaseEvents()` (reflection). Correct: none raised yet (`08` E3/E8). |
| C15 tests | Paused per AGENTS.md §6 — 104 ad-hoc assertions in two deleted scripts; no `tests/`. |
| C16 file existence | PASS | The two edited paths are the step's own; the exception path was verified absent before writing. |
| C17 repairs | **2 rounds, 2 distinct signatures** | Both fixed, neither repeated — see below. Within the A6b limit. |

### Repair rounds (09 A6b)

**Round 1 — the `with()` helper could not clear a nullable field.**
- *Signature:* `str_contains()`/value failure + `SyncEventEntity::markSucceeded(...)->lockedAt` + the
  `lockedAt: $lockedAt ?? $this->lockedAt` line in `with()`.
- *Diagnosis:* `null ?? $x` is `$x`, so `lockedAt: null` meant "keep the old lease" rather than "clear
  it". A succeeded, retried or dead event therefore kept a live-looking lease: the reaper would find it
  stale and resurrect finished work, and `claim()` would be blocked where it should not be. Confirmed by
  direct execution — `markSucceeded` left `lockedAt` at the claim instant.
- *Bounded repair:* `with()`'s three nullable parameters take a `KEEP` sentinel as their default and
  assign the argument whenever it is not the sentinel. One method changed; no signature visible outside
  the class, so no tree-wide scan was owed.
- *Outcome:* the signature did not return. Every clearing transition now clears.

**Round 2 — the reaper could walk `attempts` to the tinyint ceiling.**
- *Signature:* the loop in the overflow check reaching `attempts === 255` + `releaseStaleLock()`'s
  increment in `core/CrmSync/Domain/Entities/SyncEventEntity.php`, reached from a 400-iteration
  reclaim loop.
- *Diagnosis:* `releaseStaleLock()` incremented without bound. S1 keeps `MAX_ATTEMPTS` far below 255 so
  reaper increments cannot overflow — but that only helps if the reaper *stops* at the ceiling, which
  it did not. A crash-looping event could be reclaimed until `attempts` hit 255, at which point the
  `unsignedTinyInteger` write is refused or truncated, and the event is stuck in `processing` forever
  with a counter that no longer means anything. Distinct signature from Round 1.
- *Bounded repair:* one guard added to `releaseStaleLock()` mirroring `scheduleRetry()`: it throws
  `exhausted()` rather than releasing an event with no attempts left. One method.
- *Outcome:* the signature did not return. The reaper stops at the ceiling from every starting point
  (max observed 254), and the caller is told the event's remaining move is `markDead()`.
- *Note:* this raises a **behavioural question for Step 3.5** — the reaper must now handle that throw
  and dead-letter the event rather than let it propagate. Recorded below and in D-043.

Two further red assertions in the same pass were **test errors, not code defects**, and are not repair
rounds: an assertion that assumed `attempts` reaches `MAX_ATTEMPTS` through `scheduleRetry()` (it stops
one below, because the fifth retry is the one refused), and a `===` comparison of two separately
constructed `DateTimeImmutable` objects (identity, not value).

### Rule 05 R1/R3, 08 E3/E8, 06 T8 walk

- **R1:** every transition rule is an entity method ✔; `readonly class`, not `final` ✔; a named
  constructor (`enqueue()`) that cannot produce an invalid state ✔; time and jitter passed in ✔;
  violations raise `SyncEvent<Reason>Exception` for the Service to convert ✔; no rule left for a
  Service ✔. Item 4 (events) is correctly **not** applied: no method raises one.
- **R3:** the rules class is pure and holds no state ✔.
- **E3/E8:** no `domainEvents`, no `releaseEvents()`, no dispatch ✔.
- **T8:** A zero, B zero ✔.

## Commands run

`git check-ignore -v …` (guard, no output); `php -l` over 18 Domain files; `composer dump-autoload`;
the T8 A/B, I/O, clock/random and leftover greps; two scripted verification suites (104 assertions)
covering the rules' arithmetic, every transition, every illegal move, immutability, the ceiling walk,
the reaper's bound across eight starting points, and lease staleness; plus direct PHP runs to diagnose
both defects. No migration, no seeder, no test run, no git write, no DB query, no `framework/` edit.
Both temporary scripts were deleted.

## Decisions

Recorded in `DECISIONS.md`:

- **D-042** — the attempt counter increments on the failure (`scheduleRetry`, `releaseStaleLock`), never
  on the claim; and `with()` uses a `KEEP` sentinel so a nullable field can be cleared.
- **D-043** — `releaseStaleLock()` refuses an exhausted event instead of releasing it, which is what
  actually keeps `attempts` clear of the `unsignedTinyInteger` ceiling; the reaper (Step 3.5) must
  therefore treat that exception as the signal to dead-letter the event.

## Deviations

1. **`jobClass()` and `hasStaleLease()` are two methods beyond the step's list.** `jobClass()` derives
   the `dead_letters.job_class` value from the event's direction (A6) so a caller cannot pair the wrong
   handler with a direction; `hasStaleLease()` carries the reaper's staleness rule, which `05` R1.1
   forbids putting in the reaper itself. Both are pure and hold no state.
2. **`markDead()` takes only `(DateTimeImmutable $now, SanitizedErrorMessage $error)`** as the step
   specifies — it does not also accept a raw string, so scrubbing cannot be bypassed by a caller.
3. **The reaper's new refusal is a behaviour Step 3.5 must handle.** Flagged in D-043 and at the STOP
   GATE; it is not a defect in this step but it changes what that step must do.
4. **`MAX_ATTEMPTS = 5`, base 30s, cap 3600s, lease 300s, batch 50** are my numbers. The step fixes only
   that the ceiling must be well below 255; the rest are chosen for the CRM-call context and are
   constants in one class so they can be revised in one place.

## Deferred and open items

- **Step 3.5 must catch `SyncEventTransitionException::exhausted()` from `releaseStaleLock()`** and
  dead-letter the event (see D-043). The worker loop (Step 3.2) likewise catches it from
  `scheduleRetry()`.
- **No language file for the new keys** — `crm_sync.errors.{event_not_claimable,event_not_processing,event_attempts_exhausted,invalid_jitter}`
  join the keys Steps 0.2–1.3 emit. `lang/` still holds only `en_US/companies.php`.
- The `SyncEventEntityMapper` (Step 2.3) must map the 14 columns to the unchanged constructor and
  hydrate `SyncEventStatus`/`SyncDirection`/`CrmProviderName`.
- `next_attempt_at` is written from `$now->setTimestamp(...)`, so it keeps the caller's timezone object;
  the mapper formats to UTC on persistence. Worth confirming in Step 2.3 that no local-time leak occurs.
- **D-034 remains OPEN** (inherited).

## Tests

Paused per AGENTS.md §6. The 104 assertions were two deleted ad-hoc scripts, not a suite.

## Next step

**Step 1.5 — `CrmMappingEntity` and `DeadLetterEntity`**: `link()`, `recordSync()`, `isEcho()`,
`relinkTo()`; `fromDeadEvent()` and `markReplayed()` with `DeadLetterAlreadyReplayedException` (S4).

---

### STOP GATE

- **Step:** `Lead/1.4` — **Status: DONE_WITH_NOTES**
- **Report:** `docs/ai-reports/28-syncevent-transitions.md`
- **Deliverable:** the seven transitions plus `jobClass()`/`hasStaleLease()` on `SyncEventEntity`,
  `SyncRetryRules` implemented, `SyncEventTransitionException` created; 104/104 assertions pass; T8 A/B
  zero; immutable, pure, clock-free, event-free.
- **Needs the developer's eye (not blockers):** **D-043** — the reaper now refuses an exhausted event,
  so **Step 3.5 must catch that exception and dead-letter the event**; and Deviation 4 (the retry
  numbers, which are mine).
- **Next step:** `1.5` — `CrmMappingEntity` (`link`, `recordSync`, `isEcho`, `relinkTo`) and
  `DeadLetterEntity` (`fromDeadEvent`, `markReplayed`).
- **Awaiting:** the developer's go-ahead. Nothing is started until then.
