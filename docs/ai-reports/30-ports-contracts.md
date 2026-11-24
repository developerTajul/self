# Report 30 — Step `Lead/1.6`: Ports (contracts) for both modules

**Step:** `Lead/1.6` | **Mode:** CORE-PURE | **Status:** `DONE_WITH_NOTES`
**Plan:** `docs/ai-plans/lead-module-action-plan.md` §5 Step 1.6 (line 189)
**Plan reference:** `30-ports-contracts.md` (numbering continues the project sequence; report `22`–`29` precede it)

---

## 1. §0 PRE-FLIGHT

**CONTEXT HYDRATION.** Read the last three `DECISIONS.md` entries — `D-041` (VO orderings: key-sorted hashing must preserve list order; scrub before truncating), `D-042` (attempts increments on the FAILURE not the claim; a copy-with-overrides helper needs a `KEEP` sentinel so `null` means "clear"), `D-043` (`releaseStaleLock()` refuses an exhausted event; the reaper must dead-letter it, not propagate) — and the latest `PROJECT-LOG.md` entry (`Lead/1.5`, `DONE`, report `29`), plus the previous step's report (`29-crmsync-entities.md`).

**OPEN decisions touching this step:** none. `D-041`/`D-042`/`D-043` are all `DECIDED` and all internal to Phase 1 files this step does not touch. `D-043` binds Phase 3.5 (the reaper), not this step, and is carried forward in the STOP GATE below.

**Settled questions not re-decided:** the write/read port split (`D-001` domain shape), the `AbstractWpRepository` / `BaseWriteRepository` / `BaseReadRepository` hierarchy (`AGENTS §4 D4`), and the rule that `claimDueEvents()` is a state-changing operation on the write port (plan §5 Step 1.6).

**GIT TRACKING GUARD.** `git check-ignore -v .claude/rules .claude/skills docs/ai-reports` printed **nothing** (exit 1) for all three paths. Passed.

---

## 2. GOAL AND SCOPE

Declare the ports both modules exchange through: write ports (Entities in and out, only state changes plus `findByIdForUpdate()`), read ports (no state change), and the I/O ports for CRM egress, webhook ingress, cross-module outbox and merge re-link.

**ACCEPTANCE (plan line 194):** no pure read on a write port; no WordPress type in any signature; names describe the domain operation.

**OUT OF SCOPE:** `framework/`, `vendor/`, Phase 2 adapters, Phase 3 Services. No migration. No `tests/`.

---

## 3. DELIVERABLES (FILE EXISTENCE GUARD RUN BEFORE EACH CREATION)

Every file below was checked with `git ls-files` / `git cat-file -e HEAD:<path>` before creation; all are new in this step (absent from `HEAD`).

### Write ports — `Domain/Contracts/`

| File | Methods |
| --- | --- |
| `core/Lead/Domain/Contracts/LeadRepositoryContract.php` | `create`, `update`, `delete`, `findByIdForUpdate` |
| `core/CrmSync/Domain/Contracts/SyncEventRepositoryContract.php` | `create`, `update`, `findByIdForUpdate`, `claimDueEvents(DateTimeImmutable, int): array`, `releaseStaleLeases(DateTimeImmutable): array` |
| `core/CrmSync/Domain/Contracts/CrmMappingRepositoryContract.php` | `create`, `update`, `delete`, `findByIdForUpdate` |
| `core/CrmSync/Domain/Contracts/DeadLetterRepositoryContract.php` | `create`, `createIfAbsentForEvent`, `update`, `findByIdForUpdate` |

### Read ports — `Application/Contracts/`

| File | Methods |
| --- | --- |
| `core/Lead/Application/Contracts/LeadQueryContract.php` | `findById`, `findByEmail`, `findByPhone`, `getFilteredLeads(LeadFilter): PaginatedResult` |
| `core/CrmSync/Application/Contracts/SyncEventQueryContract.php` | `getFilteredEvents(SyncEventFilter): PaginatedResult` |
| `core/CrmSync/Application/Contracts/CrmMappingQueryContract.php` | `findByLocalKey`, `findByRemoteKey`, `findAllForLocalEntity` |
| `core/CrmSync/Application/Contracts/DeadLetterQueryContract.php` | `findById`, `getDeadLetters` |

### I/O ports — `Application/Contracts/`

| File | Methods |
| --- | --- |
| `core/Lead/Application/Contracts/LeadCaptureOutboxContract.php` | `enqueueForCapturedLead(LeadEntity): void` |
| `core/Lead/Application/Contracts/LeadMappingRelinkContract.php` | `relinkMappings(int, int): int` |
| `core/CrmSync/Application/Contracts/CrmContactGatewayContract.php` | `createRecord`, `updateRecord`, `fetchRecord` |
| `core/CrmSync/Application/Contracts/WebhookSignatureVerifierContract.php` | `verify(string $rawBody, array $headers): bool` |
| `core/CrmSync/Application/Contracts/CrmProviderRegistryContract.php` | `gatewayFor`, `verifierFor` |
| `core/CrmSync/Application/Contracts/SyncMapperContract.php` | `mapToWebhookEnqueueDTO(array): WebhookEnqueueDTO` |
| `core/Lead/Application/Contracts/LeadMapperContract.php` | *(empty — signature lands with Step 3.1)* |

### Inbound service contracts

| File | Methods |
| --- | --- |
| `core/Lead/Application/Contracts/LeadCommandServiceContract.php` | `captureLead(LeadCaptureDTO): Result` |
| `core/Lead/Application/Contracts/LeadQueryServiceContract.php` | `getLead`, `getLeads` |
| `core/CrmSync/Application/Contracts/SyncCommandServiceContract.php` | `processOutboundBatch`, `enqueueInboundWebhook`, `processInboundEvent`, `releaseStaleLeases`, `replayDeadLetter` |
| `core/CrmSync/Application/Contracts/SyncQueryServiceContract.php` | `getSyncEvents`, `getDeadLetters` |

### Supporting types created with the ports

`LeadFilter` (`Domain/ValueObjects`), `LeadRules` (`Domain/Rules`), `LeadProjectionDTO` (`Domain/ReadModels`), `InvalidLeadFilterException` (`Domain/Exceptions`), `SyncEventFilter` (`Application/DataObjects`), `CrmPushResultDTO` (`Application/DTOs/Responses`), `WebhookEnqueueDTO` (`Application/DTOs/Requests`), `SyncEventProjectionDTO` and `DeadLetterProjectionDTO` (`Domain/ReadModels`).

`LeadProjectionDTO` sits in `Domain/ReadModels` rather than `Application` because `LeadQueryContract` (Application) returns it and the write port imports the Entity only — a Domain-layer projection keeps both ports pointing inward.

---

## 4. SELF-CHECK

Verification was by a temporary script (`s16-tmp.php`), **deleted after the run** (confirmed absent). It exercised reflection over the real autoloaded classes.

| Check | Result |
| --- | --- |
| Every port named by Step 1.6 exists | **PASS** (19/19) |
| No WordPress type in any port signature (reflection over params + return types, scanning `wpdb`, `WP_*`) | **PASS** — 0 violations |
| Every type referenced by a port resolves | **PASS** — 0 missing |
| Write ports declare no pure read | **PASS** — 0 hits across 12 pure-read names |
| Write ports expose `findByIdForUpdate` | **PASS** (4/4) |
| Read ports declare no state change | **PASS** — 0 hits across 7 write names |
| `SyncEventRepositoryContract` declares `claimDueEvents` + `releaseStaleLeases`; `claimDueEvents` takes `(DateTimeImmutable, int)` and returns `array` | **PASS** |
| Supporting types are `readonly` with no writable public property | **PASS** (6/6) |
| `LeadFilter` refuses `perPage` 0 and 1000; clamps `page`; blanks `search` to null; `hasCriteria()`; `LeadRules::clampPerPage` | **PASS** |
| Final tally | **pass=49 fail=0** |

**06 T8 greps (real output, run after the fix):**

- **A** — `grep -rnE '\wpdb|WP_|Framework\\|App\\|add_action|add_filter|apply_filters|do_action|wp_[a-z_]+\(|env\(|config\(' core/Lead/Domain core/Lead/Application core/CrmSync/Domain core/CrmSync/Application` → **ZERO MATCHES**
- **B** — `grep -rnE '\bnow\(\)|\bresolve\(\)|\bapp\(\)|\bview\(\)|\bbase_path\(\)|\bconfig\(\)|Illuminate|Laravel' core/Lead core/CrmSync` → **ZERO MATCHES**

**`php -l`:** clean on every file created by this step.

**Autoload:** all 15 spot-checked types resolve through `vendor/autoload.php`.

---

## 5. AUTONOMOUS FIX LOOP (09 A6b) — TWO ROUNDS USED

A defect was found by the self-check and repaired in the permitted two rounds. Each repair was bounded to the statement that produced the signature.

### Round 0 — Failure Signature

`Error: Cannot modify readonly property Core\Lead\Domain\ValueObjects\LeadFilter::$search` + `core/Lead/Domain/ValueObjects/LeadFilter.php` + **line 58**.

**Diagnosis.** `LeadFilter` is `final readonly`; `$search` was a promoted readonly property and the constructor reassigned it to normalize blanks. PHP forbids writing a readonly property twice, so the normalization was a fatal error on *every* construction that passed a search term.

**Round 1 repair (bounded).** Demoted `$search` from a promoted parameter to a plain parameter and assigned the promoted property once, at the top of the constructor. No other statement touched.

### Round 1 — Failure Signature (NEW, not repeated)

`FAIL blank search -> null` + `s16-tmp.php` + **lines 150–153**.

**Diagnosis.** The Round 1 edit assigned `trim($search)` but dropped the collapse of a blank result to `null`, so `'  '` became `''` rather than `null` and `hasCriteria()` reported criteria for an empty filter. The signature moved, so the Round 1 diagnosis was incomplete rather than wrong, and Round 2 was permitted.

**Round 2 repair (bounded).** Restored the trim-to-null collapse on that single assignment, with the original WHY comment.

### Round 2 — clean

`pass=49 fail=0`. No repeated Failure Signature across rounds. No Interface/Contract was changed by a repair, so no tree-wide contract scan was required. No A10 stop was bypassed.

---

## 6. RULE COMPLIANCE

| Rule | Status |
| --- | --- |
| `AGENTS §9` — write port in `Domain/Contracts/`, read port in `Application/Contracts/` | Met |
| `AGENTS §9` — write port declares no pure read; only `findByIdForUpdate` | Met — verified by reflection |
| `AGENTS §9` — port method names describe the domain operation | Met (`findByRemoteKey`, `claimDueEvents`, `releaseStaleLeases`) |
| `AGENTS §11` — Core exposes ports as `...Contract`; adapters live outside | Met — no implementation in Core |
| `AGENTS §7` — repositories take and return Entities, not DTOs/arrays | Met — `LeadEntity`, `SyncEventEntity`, `CrmMappingEntity`, `DeadLetterEntity` |
| `AGENTS §6` — CORE-PURE: no WordPress, `Framework\`, `App\`, helpers, facades | Met — T8 A and B both zero |
| `AGENTS §8` — inbound contracts `<Module>CommandServiceContract` / `<Module>QueryServiceContract` | Met |
| `AGENTS §4 D5` — Core has no user-facing string, never calls `__()` | Met |
| `AGENTS §4 D7/D8` — no `env()` outside `config/`; no service locator in Core | Met |
| `AGENTS §13` — constructor promotion, guard clauses, contracts end `...Contract` | Met |
| Plan §0.9 — no existing Shared base class or contract changed (H6) | Met — nothing under `core/Shared` edited |
| Plan §0.8 — no schema guessed | Met — every column referenced traces to the supplied migrations |
| `AGENTS §6` — tests paused | Met — no `tests/`, no test authored or run |

**Boundaries noted (not violations).** `SyncCommandServiceContract` and `SyncQueryServiceContract` are inbound *service* contracts, not ports; they sit in `Application/Contracts/` alongside the read ports, which `AGENTS §8` and the plan's target tree both place there. `LeadMapperContract` is empty by design: the plan schedules its signature for Step 3.1, and declaring a guess now would fix a shape the capture use case has not yet settled.

---

## 7. DESIGN NOTES

- **`claimDueEvents()` is deliberately a write-port method whose name is not a single write.** It is the atomic select-then-own operation: a candidate must be selected and then owned conditionally, or two workers take the same event. Leaving that as two adapter calls would put the correctness requirement outside the contract that promises it. Recorded in the file's docblock; Step 2.2 implements the conditional `UPDATE ... WHERE id = ? AND status = 'pending'` with an affected-row check.
- **`DeadLetterRepositoryContract::createIfAbsentForEvent()`** exists because `sync_events` may dead-letter the same event twice across a reaper pass and a worker failure; the `sync_event_id` UNIQUE column makes the second insert a duplicate, and the port returns the existing row rather than throwing through the write path. It is a state change, so it belongs on the write port.
- **The two cross-module ports** (`LeadCaptureOutboxContract`, `LeadMappingRelinkContract`) are owned by `Lead` and implemented in `core/CrmSync/Infrastructure` (DP-1, DP-2). Neither takes an array or an id where an Entity carries the state: the payload is built from the lead's own state, so the module that knows what a CRM is told is the one that decides.

---

## 8. DECISIONS

Two entries appended to `DECISIONS.md`:

- **`D-044`** — `LeadFilter` normalizes in the constructor and must not promote the normalized property. **Type:** SELF-CHECK DEFECT. The `readonly` modifier makes the promote-and-reassign pattern a fatal error, and the failure is invisible until a value is actually passed.
- **`D-045`** — a blank search term is absence, not a term. **Type:** GAP. `''` must become `null` before it reaches the adapter, or a `LIKE '%%'` scans every row to filter nothing.

---

## 9. STOP GATE

**Status:** `DONE_WITH_NOTES` — the step is complete; the notes are the two self-check rounds in §5 and the two decisions in §8. Nothing is blocked.

**Report:** `docs/ai-reports/30-ports-contracts.md`

**Open decisions:** none. `D-041`–`D-045` are all `DECIDED`.

**Carried forward (binding, not open):** `D-043` requires Step 3.5's reaper to catch `SyncEventTransitionException` from `releaseStaleLock()` and `markDead()` that event rather than propagating. `SyncCommandServiceContract::releaseStaleLeases()` already documents this responsibility, so the contract and the decision agree.

**Next step:** `Lead/2.1` — Lead write + read repositories and `LeadEntityMapper`. Write: `create`, `update`, `delete`, `findByIdForUpdate`. Read: `findById`, `findByEmail`, `findByPhone` (normalized), filtered paginated projections using `leads_status_source_idx`.
