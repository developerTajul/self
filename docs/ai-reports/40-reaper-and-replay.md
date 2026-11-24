# Report 40 — Step `Lead/3.5`: reaper sweep and DLQ replay

**Step:** `Lead/3.5` | **Mode:** CORE-PURE | **Status:** `DONE_WITH_NOTES`
**Plan:** `docs/ai-plans/lead-module-action-plan.md` §5 Step 3.5
**Predecessor:** report `39` (Step 3.4)

---

## 1. §0 PRE-FLIGHT

Read `D-043`, `D-046`, `D-047`; latest log row; plan Step 3.5. **`D-043` and `D-046` both bind this step:** `releaseStaleLeases()` refuses an exhausted event by throwing, and its own release work has already committed when it does. Git tracking guard: no output.

---

## 2. GOAL AND SCOPE

**GOAL.** `releaseStaleLeases()` for the scheduled reaper, dead-lettering an exhausted event instead of letting the refusal abort the pass; and `replayDeadLetter()` creating one fresh event from a dead letter, once.

**ACCEPTANCE.** Refusal caught and the event moved to `dead_letters` (D-043); the releases a throwing sweep has already committed are not lost (D-046); replay locks the dead letter, creates a new event with a fresh key and the stored payload, records the replay, all in one transaction; a second replay is refused (S4).

---

## 3. FILES

| File | Action |
| --- | --- |
| `core/CrmSync/Application/Services/SyncCommandService.php` | `releaseStaleLeases()`, `replayDeadLetter()`, `deadLetterExhaustedEvent()` |
| `core/CrmSync/Application/Contracts/SyncEventQueryContract.php` | additive `findOldestExhaustedStaleEventId()` (`D-050`) |
| `core/CrmSync/Infrastructure/Repositories/WpSyncEventReadRepository.php` | implements that bounded read |

---

## 4. REAPER

**The problem D-046 leaves open.** `SyncEventRepositoryContract::releaseStaleLeases()` returns the list of released events, but it must throw when it meets an exhausted one — and the throw discards the list even though the releases already committed. The exception also carries no event id, so the caller cannot name the row it must dead-letter.

**How this step resolves it without touching the frozen contract.** A bounded, read-only query on the **read** port: `findOldestExhaustedStaleEventId($now)`. It selects the oldest `processing` row whose lease has expired and whose `attempts` have reached the ceiling — exactly the row the write-side sweep just refused.

**Loop shape.**

1. One transaction runs the sweep; a `SyncEventTransitionException` is caught **inside** the closure and returned, so the transaction commits the releases (D-046) instead of rolling them back.
2. If the sweep returned a list, those are the releases and the pass ends.
3. If it refused, the read port names the exhausted row, and `deadLetterExhaustedEvent()` locks it, re-checks its state, marks it dead and files the dead letter in its own transaction.
4. The loop runs again for the remainder (bounded by `SyncRetryRules::BATCH_SIZE` passes).

`deadLetterExhaustedEvent()` returns `true`/`false` rather than `void`: a row another actor settled between the read and the lock is reported as "not filed" and is not counted, so the returned counts describe what happened.

**Ordering safety.** The write-side sweep drains releasable rows before rethrowing (report `32`), so a single old exhausted row cannot starve the rest of the batch; and because the dead letter is filed before the next pass, the next sweep no longer meets it.

---

## 5. REPLAY

| Clause | Evidence |
| --- | --- |
| Lock the dead letter | `deadLetterRepository->findByIdForUpdate()` |
| Missing row | closure returns `null`; the method returns `Result::notFound(...)` after commit |
| Fresh idempotency key | `hash('sha256', 'replay:' . $deadLetterId . ':' . $uuidGenerator->generate())` |
| Stored payload replayed verbatim | `payload: $deadLetter->payload` (S4: the full payload is kept for this) |
| Direction preserved | `jobClass === HANDLER_INBOUND ? inbound : outbound` |
| One replay only | `markReplayed()` throws `DeadLetterAlreadyReplayedException` when `retried_at` is set; `executeInTransaction()` maps it to `crm_sync_dead_letter_replayed` |
| One transaction | the new event, the replay trail and the ledger row commit together |

The replay key includes a fresh uuid rather than the old key, because the whole point of a replay is to enqueue work the ledger has not already settled; reusing the dead event's key would collide with the dead row and be rejected as a duplicate.

---

## 6. SELF-CHECK

| Check | Result | Evidence |
| --- | --- | --- |
| C1 files | PASS | the read-port method is additive; no file created |
| C2 scope | PASS | no contract signature changed; no migration |
| C3 syntax | PASS | `php -l` clean |
| C4 frozen contract | PASS | `SyncEventRepositoryContract` untouched; the extra read lives on the application read port |
| C5 transaction | PASS | both methods route through `executeInTransaction()` |
| C6 D-043 | PASS | the refusal is caught, never propagated out of the service |
| C7 D-046 | PASS | the catch is inside the transaction callback, so releases commit |
| C8 single replay | PASS | entity guard + mapped failure code |
| C9 T8 | PASS | A: 1 pre-existing docblock match; B: 0 |

---

## 7. DEVIATIONS

1. **A diagnostic read was added to the read port.** The alternative — adding an event id to the frozen `SyncEventTransitionException` — would have edited a frozen Domain exception, which AGENTS §3 forbids in a module step. The read is bounded, read-only and lives on the read side (`D-050`).
2. **The reaper may run several passes in one call** (bounded by `BATCH_SIZE`), because each pass can settle only one exhausted row.
3. **The dead-letter filing re-checks the row's state** after locking; a row that is no longer `processing` or no longer exhausted is left alone rather than dead-lettered twice.

---

## 8. STOP GATE

**Status:** `DONE_WITH_NOTES`. Next step: `Lead/3.6`.
