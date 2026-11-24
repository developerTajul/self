# Report 32 — Step `Lead/2.2`: SyncEvent write + read repositories (atomic claim, reaper)

**Step:** `Lead/2.2` | **Mode:** ADAPTER | **Status:** `DONE_WITH_NOTES`
**Plan:** `docs/ai-plans/lead-module-action-plan.md` §5 Step 2.2 (line 207)
**Predecessor:** report `31` (Step 2.1)

---

## 1. §0 PRE-FLIGHT

**CONTEXT HYDRATION.** Read the last three `DECISIONS.md` entries and the latest `PROJECT-LOG.md` row (`Lead/2.1`, `DONE`), plus report `31`.

**OPEN decisions touching this step:** none. **Binding decisions followed:**

- **`D-025`** — module conflict exception via `BaseWriteRepository::duplicateException()`; no global translator. `WpSyncEventWriteRepository` names `SyncEventDuplicateException` (created this step).
- **`D-043`** — `releaseStaleLock()` refuses an exhausted event by throwing, and the **reaper pass catches it and dead-letters** (Step 3.5). This step's repository must therefore *name* the refusal rather than absorb it. See §4 for how the batch is kept from starving.
- **`D-026`** — InnoDB semantics relied on for `FOR UPDATE`.

**GIT TRACKING GUARD.** `git check-ignore -v .claude/rules .claude/skills docs/ai-reports` printed **nothing**. Passed.

---

## 2. GOAL AND SCOPE

Implement the ledger's write adapter: enqueue, the atomic claim, the reaper's stale-lease release, `findByIdForUpdate`, plus the mapper both depend on.

**SCOPE NOTE.** The plan names `WpSyncEventReadRepository` in the target tree, but its own comment reserves it for **Step 3.6** ("the audit-log read and the filtered paginated projection for the admin screen"). Step 2.2 is the **write** side only; the read adapter is untouched.

**ACCEPTANCE (plan line 211):** no SELECT-then-blind-UPDATE; transition rules are not re-implemented in SQL; `attempts` ceiling respected.

---

## 3. FILES

| File | Action |
| --- | --- |
| `core/CrmSync/Infrastructure/Mappers/SyncEventEntityMapper.php` | implemented (was a `LogicException` stub) |
| `core/CrmSync/Infrastructure/Repositories/WpSyncEventWriteRepository.php` | implemented (was a TODO stub) |
| `core/CrmSync/Domain/Exceptions/SyncEventDuplicateException.php` | **created** (required by the acceptance; scheduled by `D-025`) |

`WpSyncEventReadRepository` was **not** touched.

---

## 4. WHAT WAS BUILT

### The atomic claim

Two statements, deliberately:

1. **Pure range scan** over `sync_events_status_next_attempt_idx` — `SELECT id WHERE status = 'pending' AND next_attempt_at <= ? ORDER BY next_attempt_at, id LIMIT ?`. Names candidates, takes nothing.
2. **Per candidate, one conditional update** — `UPDATE ... SET status='processing', locked_at=?, updated_at=? WHERE id=? AND status='pending'`. The row is kept only when the driver reports **exactly one** affected row; zero means another worker won the race, which is dropped silently rather than reported.

`SELECT ... FOR UPDATE` over the candidate batch was rejected on purpose and the reasoning is in the docblock: it holds row locks across the whole scan, so two workers claiming concurrently serialise on the first candidate rather than merely losing the race for it. Here the `UPDATE` is the only locking statement and it locks one row at a time.

Also verified: the claim is its own short operation that commits before any HTTP call (Step 3.2 depends on that, and this repository opens no transaction of its own).

### The reaper

Inside the caller's transaction, a bounded batch of stale `processing` rows (`locked_at < now - LEASE_TIMEOUT_SECONDS`) is read `FOR UPDATE`, each is released through `SyncEventEntity::releaseStaleLock()`, and each is persisted. The staleness boundary in SQL is the exact negation of the entity's own `hasStaleLease()`, so both agree on the boundary case.

**Transition rules stay in the Domain.** The ceiling, the required starting state and the resulting counter value are all decided by the entity; the adapter does not re-express them as a bulk `UPDATE`.

### The starvation fix (my own decision, within the step)

The first implementation let `SyncEventTransitionException` propagate at the moment it was met. Because the scan is ordered `locked_at ASC`, a single exhausted row older than everything behind it would abort the pass at the **same position on every pass**, and no row after it would ever be reclaimed — a permanently stuck queue.

The fix: keep the refusal (as `D-043` requires) but **drain the batch first and rethrow afterwards**. Verified directly against the database that when the sweep throws:
- the healthy row *behind* the exhausted one **is** released in storage (`pending`, attempts incremented, lease cleared);
- the exhausted row is left `processing` at its ceiling for Step 3.5 to dead-letter.

The residual trade-off is recorded as **`D-046`**: when the sweep throws, the caller receives no released-list, even though the releases are already committed. That is acceptable because `D-043`'s caller dead-letters the named event and calls again — and the alternative (returning a partial list) would need a second return channel the frozen contract does not have.

### The mapper

Explicit casts for all fourteen columns; three enum hydrations that **raise** on an unknown value; `payload_json` decoding that **raises** rather than yielding `[]`, because the column is NOT NULL and a replay built from an empty payload (S4) would report success while telling the CRM nothing. Nullable fields are written explicitly including nulls, so a cleared lease reaches storage as SQL `NULL` through the shared `write()` helper.

---

## 5. SELF-CHECK

### Static

| Check | Result |
| --- | --- |
| `php -l` on all three files | **PASS** |
| T8 — no `Framework\`, `App\`, `Illuminate` in `core/CrmSync/Infrastructure` | **PASS** — ZERO MATCHES |
| No `Application\Services` import in Infrastructure | **PASS** — ZERO MATCHES |
| Every class named in a `catch`/`throw new`/`instanceof` is imported (a missing `use` is invisible to `php -l`) | **PASS** — all five files clean, via a purpose-written checker |
| Claim is a conditional `UPDATE` with an affected-row check | **PASS** — `WpSyncEventWriteRepository.php:228,251` |
| No SELECT-then-blind-UPDATE | **PASS** — the only pre-claim read is a pure `id` range scan |

### Dynamic — against the real WordPress database

Temporary script `s22-tmp.php` bootstrapped `wp-load.php`. **Deleted after the run**, along with every other temp file (`s16-tmp.php`, `s21-tmp.php`, the probe files and the import checker) — confirmed absent from disk and from `git status`. The script cleared the table before starting so no earlier run's rows could colour an assertion, and deleted every row it created (12 rows) at the end; a follow-up count confirmed **0 residual rows** in `sync_events` and **0** in `leads`.

| Group | Checks | Result |
| --- | --- | --- |
| create / enqueue (id, key, default status, attempts 0, due time, no lease, payload round trip, derived handler) | 8 | PASS |
| duplicate idempotency key → `SyncEventDuplicateException` + translation key | 2 | PASS |
| claim: due event taken, becomes `processing`, holds a lease | 3 | PASS |
| claim: a second worker **cannot** take the same event | 1 | PASS |
| claim: a future-dated event is not claimed | 1 | PASS |
| claim: batch size 0 claims nothing; the batch cap is honoured | 2 | PASS |
| success transition (persisted, lease cleared, `processed_at` set) | 3 | PASS |
| retry transition (returns to `pending`, attempts 1, lease cleared, error stored) | 5 | PASS |
| reaper releases a stale lease through the Entity (claimed first, then aged) | 5 | PASS |
| reaper does **not** touch a live lease | 1 | PASS |
| **an exhausted stale row does not starve the rows behind it** | 6 | PASS |
| `D-043`: the reaper refuses an exhausted event **by name**, row left intact at the ceiling | 4 | PASS |
| reaper does not touch a pending or succeeded event | 2 | PASS |
| dead transition (persisted, lease cleared, reason stored, `processed_at`) | 5 | PASS |
| cleanup: every created row removed, nothing left `processing` | 2 | PASS |
| **Total** | **53** | **53 pass / 0 fail** |

### AUTONOMOUS FIX LOOP (09 A6b) — TWO ROUNDS USED, both on the step's own code

**Round 0 — Failure Signature:** `the healthy row BEHIND the exhausted one was still released` (false) + `core/CrmSync/Infrastructure/Repositories/WpSyncEventWriteRepository.php` + `releaseStaleLeases()`.

**Diagnosis.** The `catch (SyncEventTransitionException $exception)` block had **no matching `use` statement**. The name therefore resolved to `Core\CrmSync\Infrastructure\Repositories\SyncEventTransitionException` — a class that does not exist — so the catch never matched and the exception propagated straight through the loop, aborting the whole sweep. `php -l` cannot see this; it was confirmed by inspecting the batch state directly in the database.

**Round 1 repair (bounded).** Added the missing import. Re-verified reflectively that every caught/thrown class in all five step files now resolves.

**Round 1 — Failure Signature (NEW, not repeated):** `the reaper incremented attempts` + `a released event lost its lease` (both false) + `s22-tmp.php` reaper section.

**Diagnosis.** My **test** aged the lease of an event that `claimDueEvents()` had correctly *declined*, because the preceding retry had pushed `next_attempt_at` a backoff interval into the future. The row was therefore `pending` with a lease my own raw `UPDATE` had written, and the sweep rightly ignored it. Confirmed by printing the row and the sweep's own `WHERE` match count (`0 row(s)`). The repository was correct throughout.

**Round 2 repair (bounded).** Made the probe bring `next_attempt_at` back before claiming, which is the real-world sequence.

**Round 2 — clean.** No repeated Signature across rounds. No Interface/Contract was changed by a repair (the one production change was an added `use` line), so no tree-wide contract scan was required. No A10 stop was bypassed.

Three further **test-only** corrections followed in the same section (tolerating the expected throw in a later sweep, clearing a deliberately-left exhausted row, re-claiming before `markDead`) — each was a harness fix, each verified against the database, and none changed production code.

---

## 6. RULE COMPLIANCE

| Rule | Status |
| --- | --- |
| `AGENTS §9` — write adapter implements only the write port; no pure read on it; the claim is a state-changing operation on the write port | Met |
| `AGENTS §10`/plan — the claim is its own short transaction boundary; the reaper runs inside the caller's transaction and opens none itself | Met — this adapter never calls `beginTransaction`/`commit`/`rollback` |
| `AGENTS §11` — no business rule in the adapter; duplicate key via the module exception; no vendor exception into Core | Met |
| `AGENTS §7` — every behaviour method returns a NEW Entity; the adapter never mutates one | Met — `releaseStaleLock()` and the transitions all return new instances |
| `02` §7.1 — every value prepared; identifiers constants; no `%i` | Met |
| `02` §7.3 — `findByIdForUpdate()` via `findRecordForUpdate()` | Met |
| `02` §7.4 — `ARRAY_A` rows cast explicitly | Met |
| `02` §7.5 — UTC throughout; the stored instant is normalized to UTC before formatting | Met |
| `02` §7.6 — a `false` return is failure, `0` rows is not; silent failure forbidden | Met — the candidate scan reads `last_error` because `get_col()`/`get_results()` report failure by leaving `last_error` set, not by returning `false` |
| `AGENTS §4 D4` — no Shared base class changed (H6) | Met — nothing under `core/Shared` touched |
| `AGENTS §13` — `final`, promotion, guard clauses, short docblocks | Met |
| `AGENTS §6` — tests paused | Met — no `tests/`, no test authored or run |
| Plan §0.8 — no schema guessed | Met — every column traces to `2026_11_22_210100_create_sync_events_table.php` |

---

## 7. HANDOVER NOTES

**To the DB Optimizer lens (rule 13).** The claim is an index-served range scan (`sync_events_status_next_attempt_idx`) followed by *n* single-row conditional updates, where *n* = batch size (≤ 50): `O(n)` statements per pass, each a primary-key update. The reaper is `O(n log n)` for the ordered scan of a bounded batch plus one update per row, with *n* ≤ `BATCH_SIZE`. Read/claim `FOR UPDATE` scope is deliberately narrowed to one row per statement in the claim, and to a bounded batch in the reaper. **Open concern for Step 3.5:** `releaseStaleLeases` re-scans an exhausted row on every pass until the caller dead-letters it; the drain-first fix keeps it from starving others, but it is still rescanned. Resolving it properly needs the caller (Step 3.5) to complete the dead-letter loop, which is where `D-043` puts it.

**To the Security Auditor lens (rule 14).** Every value bound through `prepare()`; identifiers are class constants; no request value reaches SQL as text; the adapter raises on failure rather than serving absence. No nonce/capability concern arises in Infrastructure.

**Carried forward (binding).** `D-043`: Step 3.5's reaper must catch `SyncEventTransitionException` from the release and `markDead()` the named event. New: **`D-046`** — a throwing sweep discards its released-list, so Step 3.5 must re-sweep after dead-lettering.

---

## 8. STOP GATE

**Status:** `DONE_WITH_NOTES`. The notes are the two self-check rounds in §5, the starvation fix in §4, and `D-046`. Nothing is blocked.

**Report:** `docs/ai-reports/32-syncevent-repositories.md`

**Open decisions:** none. `D-043` and `D-046` are binding on Step 3.5, not open.

**Next step:** `Lead/2.3` — CrmMapping + DeadLetter repositories. CrmMapping: lookup by the three-part remote key and the four-part local key, hash persistence, both UNIQUE indexes honoured. DeadLetter: `mediumText` message (already scrubbed by the Entity), `sync_event_id` unique, replay columns per schema; no `created_at` / `updated_at` columns are written.
