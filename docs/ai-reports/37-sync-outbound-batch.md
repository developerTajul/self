# Report 37 — Step `Lead/3.2`: `SyncCommandService::processOutboundBatch`

**Step:** `Lead/3.2` | **Mode:** CORE-PURE | **Status:** `DONE_WITH_NOTES`
**Plan:** `docs/ai-plans/lead-module-action-plan.md` §5 Step 3.2
**Predecessor:** report `36` (Step 3.1)

---

## 1. §0 PRE-FLIGHT

Read `D-046`, `D-047`, `D-048`; latest log row `Lead/2.5`; report `35`; plan Step 3.2. No OPEN decision blocks this step. Git tracking guard: no output. `PLAN-3.2` binds the claim/HTTP/record split; `D-047` forbids recomputing the outbox key.

---

## 2. GOAL AND SCOPE

**GOAL.** Claim a bounded batch, call the CRM **outside** any transaction, record each outcome in its own short transaction, and never let one event's failure leave the ledger in a state the reaper cannot recover.

**ACCEPTANCE.** (a) claim in its own transaction; (b) gateway called outside a transaction; (c) per-event short transaction: success → `markSucceeded` + mapping link/`recordSync`; transient → `scheduleRetry`; permanent or exhausted → `markDead` + dead letter; (d) no HTTP inside a transaction; (e) no swallowed `Throwable` after a write; (f) a crash after the claim is recoverable by the reaper.

---

## 3. FILES

| File | Action |
| --- | --- |
| `core/CrmSync/Application/Services/SyncCommandService.php` | implemented `processOutboundBatch()` + `recordOutcome()` + `recordMapping()` + `performRemoteOperation()` |
| `core/CrmSync/Application/DTOs/Responses/SyncBatchResponseDTO.php` | created: claimed / succeeded / retried / dead |
| `core/CrmSync/Application/Contracts/CrmProviderRegistryContract.php` | existing port, first consumer |
| `core/CrmSync/Infrastructure/Adapters/HubSpotProviderRegistry.php` | created (`D-052`): resolves the configured gateway/verifier |
| `app/Providers/CrmSyncServiceProvider.php` | registry bound, command-service arguments matched to the constructor |

---

## 4. THE THREE FRAMES (verified against the code)

| Frame | What it does | Evidence |
| --- | --- | --- |
| Claim | one transaction that only claims | `processOutboundBatch()`: `executeInTransaction(fn () => claimDueEvents(...))` |
| Remote call | **outside** every transaction | `performRemoteOperation()` is called in the loop, after the claim transaction returned |
| Record | one short transaction per event | `recordOutcome()` re-locks the row, then transitions and persists |

The split is visible in the call order, not just in comments: the claim's `Result` is unwrapped to a plain `array` before the loop, so no transaction is open while `$gateway->createRecord()` / `updateRecord()` / `fetchRecord()` runs.

**Outcome mapping**

| Outcome | Ledger transition | Side effect |
| --- | --- | --- |
| `success` | `markSucceeded()` | `CrmMappingEntity::link()->recordSync()` on first push, `recordSync()` afterwards |
| `transient` and attempts remain | `scheduleRetry($now, jitter)` | none |
| `transient` with no attempts left | `markDead()` | `DeadLetterEntity::fromDeadEvent()` |
| `permanent` | `markDead()` | dead letter, carrying `http_status_code` |

**Retry guard.** The guard is `SyncRetryRules::mayRetry($locked->attempts + 1)`, i.e. it asks whether the *next* attempt is allowed, not whether the current event is exhausted (`D-053`). The previous form (`! $locked->isExhausted()`) would have called `scheduleRetry()` on an event whose `attempts` already equalled `MAX_ATTEMPTS`, where the entity throws `SyncEventTransitionException` — turning a final transient failure into a lost event instead of a dead letter.

**Failure of the recording frame.** `recordOutcome()` returns a `Result` rather than an array of zero counts, so a per-event write failure is surfaced. The batch then stops and the remaining claimed rows stay in `processing` until the reaper's lease timeout reclaims them — the recovery path the plan names, not a silent zero.

---

## 5. SELF-CHECK

| Check | Result | Evidence |
| --- | --- | --- |
| C1 files | PASS | `HubSpotProviderRegistry.php` path verified absent before creation |
| C2 scope | PASS | no delivery, no migration, no `framework/` |
| C3 syntax | PASS | `php -l` clean |
| C4 contract | PASS | `implements SyncCommandServiceContract` verified by reflection |
| C5 no HTTP in transaction | PASS | call order in `processOutboundBatch()`; the gateway is reached only from `performRemoteOperation()` |
| C6 no swallowed failure | PASS | the closure throws; `executeInTransaction()` catches outside the transaction, so rollback happens first |
| C7 events | PASS | no Domain event is raised in Phase 3, so no dispatch is claimed (`08` E3) |
| C8 retry ceiling | PASS | harness check: with `attempts = MAX_ATTEMPTS - 1`, the next failure takes the dead path and `attempts` stays far below 255 |
| C9 T8 | PASS | A: 1 pre-existing docblock match; B: 0 |

---

## 6. DEVIATIONS

1. **A provider registry adapter had to be written.** `CrmProviderRegistryContract` existed with no implementation, so `SyncCommandService` could not be constructed. `HubSpotProviderRegistry` resolves the single first-release provider (DP-8) and takes the two configured adapters by injection (`D-052`).
2. **`SyncResponseMapper` is no longer a command-service dependency.** It is used by the query service only; the command service returns counts, not response DTOs.
3. **Mapping write on success happens inside the recording transaction**, after the remote call returned — so a failed mapping write rolls back the ledger transition rather than leaving a succeeded event with no mapping.

---

## 7. STOP GATE

**Status:** `DONE_WITH_NOTES`. Next step: `Lead/3.3`.
