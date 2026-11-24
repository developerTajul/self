# Step 1.5 — `CrmMappingEntity` and `DeadLetterEntity` behaviour (Lead)

**Status:** DONE

**Summary.** Implemented the behaviour on both remaining CrmSync entities. `CrmMappingEntity` gained
`link()`, `recordSync()`, `isEcho()`, `relinkTo()` and `equals()`; `DeadLetterEntity` gained
`fromDeadEvent()`, `markReplayed()` and `hasBeenReplayed()`. Two exception factories were created.
Every method returns a NEW instance, takes `$now` as an argument, and refuses the illegal call by name.
58 assertions pass on the first run — no repair round was needed. T8 A/B zero; `php -l` clean.

**Inputs used.**
- Plan `docs/ai-plans/lead-module-action-plan.md` §5 Step 1.5, plus A4, A6, S2, S4.
- `database/migrations/2026_11_22_210000_create_crm_mappings_table.php` (both UNIQUE indexes, the
  echo-suppression note) and `…_210200_create_dead_letters_table.php` (the replay columns).
- `SyncEventEntity` (Step 1.4) — `jobClass()` and the handler constants; the Step 1.3 VOs.
- Rules 01 P6, 05 R1/R3, 06 T8, 08 E3; AGENTS §5, §7, §13.

**Rules and skills applied.** AGENTS §1, §5, §7, §11, §13; rules 01, 05, 06, 08. Tests paused per §6.

---

## Pre-flight

- **CONTEXT HYDRATION.** Read the last three `DECISIONS.md` entries (D-041, D-042, D-043), the latest
  `PROJECT-LOG.md` entry (`Lead/1.4`), and the previous step's report
  (`28-syncevent-transitions.md`). The developer's message approved D-042 and D-043 and lifted the
  one-step-per-run gate for routine steps. **D-043** does not bind this step (it constrains Step 3.5's
  reaper); **D-042**'s lesson does — no copy-with-overrides helper was used here, each transition
  builds the full constructor call, so the "clear a nullable field" trap cannot recur.
- **GIT TRACKING GUARD.** `git check-ignore -v .claude/rules .claude/skills docs/ai-reports` printed
  nothing for any of the three (exit 1). **PASS** — no HALT.

## Files

**Modified — 2 files** (Step 0.2 skeletons, named by the step).

| File | Change |
| --- | --- |
| `core/CrmSync/Domain/Entities/CrmMappingEntity.php` | Added `link()`, `recordSync()`, `isEcho()`, `relinkTo()`, `equals()`. Constructor unchanged (10 params). |
| `core/CrmSync/Domain/Entities/DeadLetterEntity.php` | Added `fromDeadEvent()`, `markReplayed()`, `hasBeenReplayed()`. Constructor unchanged (10 params). |

**Created — 2 files** (the exceptions the step and the entities require).

| File | Contents |
| --- | --- |
| `core/CrmSync/Domain/Exceptions/DeadLetterAlreadyReplayedException.php` | `alreadyReplayed()` factory; carries `deadLetterId` and `retriedAt`. |
| `core/CrmSync/Domain/Exceptions/SyncMappingRelinkException.php` | `invalidTargetId()`, `alreadyLinked()` factories. |

**Not touched:** the VOs, `SyncEventEntity`, the enums, Infrastructure, every other module,
`framework/`, `vendor/`, `database/`. No events scaffolded (`08` E3).

## Design decisions

**`isEcho()` is false when no hash has been recorded.** A mapping that has never been pushed has no
`lastSyncedHash`, so nothing inbound can be the echo of a write that never happened. Reporting true
would silently drop the first genuine remote change — a false echo is worse than a missed one.

**`recordSync()` is the only writer of the hash**, and it sets `lastSyncedHash` and `lastSyncedAt`
together, so the pair cannot diverge (a hash with no time, or a time with no hash).

**`relinkTo()` refuses a re-link to the row it already names.** That call would be a silent no-op the
caller reads as a completed re-link, when in fact its view of which row survived is stale. It also
refuses a target below 1, which could only produce `WHERE id = 0`.

**`fromDeadEvent()` takes the provider and job class from the event**, never as separate arguments. A
dead letter that named a provider or handler the event did not use would send an operator to the wrong
CRM; deriving both makes that mistake unrepresentable. `jobClass()` (Step 1.4) supplies the handler
from the event's direction per A6.

**The payload is stored unscrubbed, the message scrubbed.** S4 is explicit: the full payload is what a
replay needs, and it is our own data. The error message is the upstream echo and is the thing that
carries leaked credentials — it arrives already scrubbed (Step 1.3), and the entity stores what it is
given.

**`equals()` compares the keys, not the row.** Two mappings are the same mapping when their local and
remote keys agree, which is what the two UNIQUE indexes say. Ids and timestamps differ between an
unpersisted mapping and its stored self, and are not identity.

## Self-check

| Check | Result | Evidence |
| --- | --- | --- |
| C1 files exist, no unexpected file | PASS | 2 edited in place, 2 exceptions created (both referenced by the entities). |
| C2 scope audit vs brief | PASS | Only the two entities the step names and their exceptions. `framework/`/`vendor/` clean. |
| C3 `php -l` per file | PASS | All four files. |
| C4 `composer dump-autoload` | PASS | Run; 1958 classes (+2 exceptions). |
| C5 rule DoDs walked | PASS | 05 R1/R3, 08 E3, 06 T8 below. |
| C6 06 T8 greps | T8 A **zero**, T8 B **zero** | Both exit 1 over `core/CrmSync/Domain`. |
| C7 immutability | PASS | Both `readonly`, not `final`; every method returns a new instance; receiver unchanged after each (asserted). |
| C8 no clock / I-O | PASS | No `new DateTimeImmutable`/`date(`/`time()`/`random_*`/`wpdb`; `$now` is a parameter. |
| C9 mapping rules | PASS | `link` records no hash; `recordSync` sets hash+time together; `isEcho` false without a hash and true only for the same canonical value; `relinkTo` refuses `< 1` and the row already named; `equals` differs on each of the four keys. |
| C10 dead-letter rules | PASS | `fromDeadEvent` derives provider/jobClass from the event (both directions checked), keeps the full payload, stores the scrubbed message, keeps `sync_event_id` and `http_status_code`, leaves `retried_at` null; `markReplayed` sets the trail and **refuses a second replay (S4)** with the exception carrying `retriedAt`; the refusal leaves the first replay's id intact. |
| C11 no hardcoded user-facing text | PASS | Exception messages and `TRANSLATION_PREFIX` keys only; no `__()`. |
| C12 no events | PASS | No `domainEvents`/`releaseEvents()` on either (reflection). |
| C13 tests | Paused per AGENTS.md §6 — 58 ad-hoc assertions in a deleted script; no `tests/`. |
| C14 file existence | PASS | The two edited paths are the step's own; both exception paths verified absent before writing. |
| C15 repairs | **none** | First self-check passed; no A6b round ran. |

### Rule 05 R1/R3, 08 E3, 06 T8 walk

- **R1:** every rule is an entity method ✔; `readonly`, not `final` ✔; named constructors (`link()`,
  `fromDeadEvent()`) that cannot produce an invalid state ✔; time passed in ✔; violations raise a named
  exception ✔; no rule left for a Service ✔. Item 4 (events) does not apply — none raised.
- **R3:** the entities validate their own invariants and return new instances ✔.
- **E3/E8:** neither carries events, dispatches, or releases ✔.
- **T8:** A zero, B zero ✔.

## Commands run

`git check-ignore -v …` (guard, no output); `php -l` over the four files; `composer dump-autoload`; the
T8 A/B and boundary greps; a scripted 58-assertion verification covering both entities, every refusal
and the reflection properties. No migration, no seeder, no test run, no git write, no DB query, no
`framework/` edit. The temporary script was deleted.

## Decisions

None new. D-042's lesson (a copy-with-overrides helper must distinguish "omit" from "set to null") was
applied by construction: every transition here writes the full constructor call, so no such helper
exists to get wrong. No new decision was needed for S4 (Step 1.4's report and the plan already cover
the single-replay rule) or A4/A6.

## Deviations

1. **`CrmMappingEntity::equals()` and `DeadLetterEntity::hasBeenReplayed()` are two methods beyond the
   step's list.** `equals()` follows D-038's convention for value comparison and is what a merge's
   "have I already seen this mapping?" test needs; `hasBeenReplayed()` exposes the S4 rule that
   `markReplayed()` enforces, so a caller can ask without re-implementing it (which `05` R1.1 forbids
   outside the entity). Both are pure.
2. **`SyncMappingRelinkException` is a second exception beyond the step's named
   `DeadLetterAlreadyReplayedException`.** `relinkTo()` refuses two illegal calls, and the step's
   "Illegal transitions throw `SyncEvent<Reason>Exception`" pattern (Step 1.4) extends naturally to a
   module-shaped name here; a bare `RuntimeException` would report a stale-view bug as a database
   fault.
3. **`fromDeadEvent()` takes `?int $httpStatusCode = null`** although the step lists it only as a
   column. The status is known at the failure site and is the one fact that separates a rejection from
   an outage at a glance; passing it is optional, so the signature does not force it.

## Deferred and open items

- **No language file for the new keys** — `crm_sync.errors.{dead_letter_already_replayed,invalid_relink_target,mapping_already_linked}`
  join the accumulating set. `lang/` still holds only `en_US/companies.php`.
- Step 2.3 builds the mappers that reconstruct these entities (`RemoteId::fromCanonical()`,
  `ContentHash::fromString()`, `SanitizedErrorMessage::fromString()`).
- Step 2.4's `LeadMappingRelinkAdapter` is the caller that must handle `SyncMappingRelinkException`
  (it deletes a conflicting mapping per A4 rather than re-pointing it).
- Step 3.5's replay path is the caller of `markReplayed()` and must surface the single-replay refusal.
- **D-034 remains OPEN** (inherited).

## Tests

Paused per AGENTS.md §6. The 58 assertions were a deleted ad-hoc script, not a suite.

## Next step

**Step 1.6 — Ports (contracts) for both modules**: the WRITE ports, READ ports and I/O ports, with
`findByIdForUpdate()` and the state-changing ledger operations. No STOP GATE follow-up per the
developer's override: execution continues.
