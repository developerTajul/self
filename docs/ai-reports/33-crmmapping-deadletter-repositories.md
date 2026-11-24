# Report 33 — Step `Lead/2.3`: CrmMapping + DeadLetter repositories

**Step:** `Lead/2.3` | **Mode:** ADAPTER | **Status:** `DONE`
**Plan:** `docs/ai-plans/lead-module-action-plan.md` §5 Step 2.3 (line 213)
**Predecessor:** report `32` (Step 2.2)

---

## 1. §0 PRE-FLIGHT

**CONTEXT HYDRATION.** Read the last three `DECISIONS.md` entries (`D-044`, `D-045`, `D-046`), the latest `PROJECT-LOG.md` row (`Lead/2.2`, `DONE_WITH_NOTES`), and report `32`.

**OPEN decisions touching this step:** none. **Binding decisions followed:** `D-025` (module conflict exceptions via `duplicateException()`; no global translator) and `D-026` (InnoDB semantics for `FOR UPDATE`).

**GIT TRACKING GUARD.** `git check-ignore -v .claude/rules .claude/skills docs/ai-reports` printed **nothing**. Passed.

---

## 2. GOAL AND SCOPE

Implement the two remaining `$wpdb` adapter pairs: CRM mappings (two lookups, hash persistence, both UNIQUE indexes honoured) and dead letters (`mediumText` message, unique `sync_event_id`, replay columns, no `created_at`/`updated_at`).

**ACCEPTANCE (plan line 215):** both UNIQUE indexes honoured on `crm_mappings`; dead letters write no `created_at`/`updated_at`.

---

## 3. FILES

| File | Action |
| --- | --- |
| `core/CrmSync/Infrastructure/Mappers/CrmMappingEntityMapper.php` | implemented (was a stub) |
| `core/CrmSync/Infrastructure/Mappers/DeadLetterEntityMapper.php` | implemented (was a stub) |
| `core/CrmSync/Infrastructure/Repositories/WpCrmMappingWriteRepository.php` | implemented |
| `core/CrmSync/Infrastructure/Repositories/WpCrmMappingReadRepository.php` | implemented |
| `core/CrmSync/Infrastructure/Repositories/WpDeadLetterWriteRepository.php` | implemented |
| `core/CrmSync/Infrastructure/Repositories/WpDeadLetterReadRepository.php` | implemented |
| `core/CrmSync/Domain/Exceptions/CrmMappingDuplicateException.php` | **created** (`D-025`) |
| `core/CrmSync/Domain/Exceptions/DeadLetterDuplicateException.php` | **created** (`D-025`) |

**Scope note.** The stub comments in four of these files named Steps 2.4/3.5/3.6, but the **plan is authoritative** and puts both repository pairs in Step 2.3 (line 213). The plan's Step 2.4 is the *cross-module adapters*, and Steps 3.5/3.6 are the *Services*. The stub comments are stale; the plan was followed.

---

## 4. WHAT WAS BUILT

### CrmMapping — both UNIQUE indexes, written for their own column order

`findByLocalKey` predicates on `(entity_type, local_entity_id, provider, crm_object_type)` — the exact column order of `crm_mappings_local_provider_object_unique`. `findByRemoteKey` predicates on `(provider, crm_object_type, crm_record_id)` — the exact order of `crm_mappings_remote_record_unique`. `findAllForLocalEntity` uses the leading two columns of the local index.

The predicates are written out per method rather than shared behind one "find by criteria array" helper, deliberately: a shared builder would make the column order an accident of the caller's array, and the index would stop being used the first time someone reordered it. The reasoning is in the class docblock.

The remote id is rebuilt through `RemoteId::fromCanonical()` rather than `fromString()`: the column only ever holds a canonical id, and re-deriving it would be a second place for the provider's canonicalization rule to disagree with itself.

`CrmMappingDuplicateException` distinguishes the **two** forms of collision (`forLocalKey()` / `forRemoteKey()`) because their remedies differ — the first is "already linked", the second is the A4 merge conflict. The adapter raises the generic form, since the index is not parseable from the localised driver message (D-025); the caller refines it where the distinction matters.

### DeadLetter — the columns the table does not have

The mapper writes **no `created_at` or `updated_at`**, which the migration states explicitly and the step's acceptance calls out. Writing them would make `$wpdb->insert()` fail on an unknown column. Its own timestamps are `failed_at` and `retried_at`, both entity-owned.

`createIfAbsentForEvent()` is idempotent by intent: a worker and the reaper can both conclude that one event is dead, and the second should record nothing rather than raise. The collision is caught and the **existing** row is read back by `sync_event_id` — the column that caused it — so the method returns the row that actually blocked the insert rather than a guess.

The inspector page excludes `payload_json` (the table's most expensive column, never rendered) but **includes** the scrubbed `error_message`, which is the field an operator reads to decide whether a replay is worth attempting.

The error message is re-scrubbed on the way **in** as well as out. That is deliberate rather than redundant: scrubbing is idempotent, and a row written before a scrubbing rule existed, or edited by hand, would otherwise be handed to an administrator unscrubbed.

### Notable choice

`WpCrmMappingWriteRepository::delete()` treats a zero-row delete as an **ordinary no-op**, not a not-found failure — matching the frozen contract, which declares no not-found exception for this port. The reason is in the docblock: A4's relink reads and deletes inside one transaction, so a missing row would mean the read and the delete disagreed, and the caller's next step is the same either way.

---

## 5. SELF-CHECK

### Static

| Check | Result |
| --- | --- |
| `php -l` on all eight files | **PASS** |
| T8 — no `Framework\`, `App\`, `Illuminate` in `core/CrmSync/Infrastructure` | **PASS** — ZERO MATCHES |
| Every class named in a `catch`/`throw new`/`instanceof` is imported | **PASS** — all six files clean |
| Both write adapters extend `BaseWriteRepository`; both read adapters extend `BaseReadRepository` and expose no write method | **PASS** — verified by reflection |
| `core/Shared` unchanged since HEAD (H6) | **PASS** — `git diff HEAD` shows the Shared files unchanged; the port edits are Step 0.2's committed baseline |
| `framework/` and `vendor/` untouched | **PASS** — `git status` empty for both |

### Dynamic — against the real WordPress database

Temporary script `s23-tmp.php` bootstrapped `wp-load.php`, cleared the three tables it touches before starting, and deleted them again at the end. **Deleted after the run**; no temp file remains (`ls` confirms absence).

| Group | Checks | Result |
| --- | --- | --- |
| contracts honoured; read adapters inherit no write method | 5 | PASS |
| CrmMapping create / read back (id, local id, remote id, object type, no hash yet, `created_at`) | 6 | PASS |
| lookup by the four-part local key, including three miss cases | 4 | PASS |
| lookup by the three-part remote key, hit and miss | 2 | PASS |
| `findAllForLocalEntity` (both, correct type, empty for another entity) | 3 | PASS |
| the **local** UNIQUE index is honoured + translation key | 2 | PASS |
| the **remote** UNIQUE index is honoured (the A4 conflict) | 1 | PASS |
| `recordSync` persists the hash; `isEcho` true/false; hash survives a round trip | 5 | PASS |
| `relinkTo` re-points only the local id, keeps hash and remote id; both keys re-resolve | 5 | PASS |
| `findByIdForUpdate` inside a transaction, hit and miss | 2 | PASS |
| mapping delete removes the row; a second delete does not throw | 2 | PASS |
| DeadLetter create / read back (id, handler from event, provider, event id, status, `failed_at`, full payload, `retried_at` null) | 8 | PASS |
| the message is scrubbed of the bearer token **and** the query token | 2 | PASS |
| the table really has no `created_at`/`updated_at`, and the mapper writes neither | 3 | PASS |
| the `sync_event_id` UNIQUE index is honoured | 1 | PASS |
| `createIfAbsentForEvent` is idempotent and returns the existing row | 3 | PASS |
| `findByIdForUpdate` on a dead letter (hit, payload decoded) | 2 | PASS |
| replay recorded once (S4): trail set, entity refuses a second, persisted, payload and `failed_at` unchanged | 7 | PASS |
| a null `sync_event_id` inserts fine (nullable FK) | 2 | PASS |
| the inspector page (type, total, projection type, scrubbed message, replayability, http status, both filters, page clamp) | 8 | PASS |
| count and slice agree | 2 | PASS |
| cleanup: every touched table empty again | 1 | PASS |
| **Total** | **77** | **77 pass / 0 fail** |

**No repair rounds were needed.** The AUTONOMOUS FIX LOOP was not entered: the first run passed every check. No Failure Signature was produced.

**Complexity (rule 13).** `findByLocalKey`, `findByRemoteKey` and `findAllForLocalEntity` are point lookups or bounded ranges served by one of the two UNIQUE indexes — `O(log n)` in table size. `getDeadLetters` is a `COUNT(*)` (`O(n)` unfiltered) plus a `LIMIT/OFFSET` slice (`O(offset + p)`); the `replayed` filter predicates on `retried_at`, which is **not** index-leading for that filter alone, so the count scans. That is recorded as accepted: every dead-letter row is a permanent failure an operator must look at, so the table is small by construction.

---

## 6. RULE COMPLIANCE

| Rule | Status |
| --- | --- |
| `AGENTS §9` — no pure read on a write port; no state change on a read port; a class never implements both | Met — verified by reflection |
| `AGENTS §7` — repositories take and return Entities | Met |
| `AGENTS §11` — no business rule in an adapter; duplicate key via the module exception; no vendor exception into Core | Met |
| `02` §7.1 — every value prepared; identifiers constants | Met |
| `02` §7.3 — `findByIdForUpdate()` via `findRecordForUpdate()` | Met |
| `02` §7.4 — `ARRAY_A` rows cast explicitly | Met |
| `02` §7.6 — `false` is failure, `0` rows is not; silent failure forbidden | Met — `get_results()` failures read from `last_error`, not from a `=== false` test that never fires |
| `02` §7.8 — `COUNT(*)` + `LIMIT/OFFSET` → `PaginatedResult` | Met |
| `AGENTS §4 D4` / H6 — no Shared base class changed | Met — `git diff HEAD` confirms |
| `AGENTS §13` — `final`, promotion, guard clauses, short docblocks | Met |
| `AGENTS §6` — tests paused | Met — no `tests/`, no test authored or run |
| Plan §0.8 — no schema guessed | Met — every column traces to its migration; the absent `created_at`/`updated_at` was verified against `SHOW COLUMNS` at runtime |

---

## 7. HANDOVER NOTES

**To the DB Optimizer lens (rule 13).** Both UNIQUE indexes are exercised by their own column order and verified in a live database. `getDeadLetters`'s `replayed` filter is not index-leading on `dead_letters_provider_retried_idx` (whose first column is `provider`), so that filter scans; accepted at this table's scale and recorded rather than hidden. The `payload_json` exclusion from the inspector projection avoids reading `longText` per row.

**To the Security Auditor lens (rule 14).** All values bound through `prepare()`; identifiers are constants; no request value reaches SQL as text. The dead-letter message is scrubbed on the way in **and** on the way out, verified against a bearer token and a query-string token. No nonce/capability concern arises in Infrastructure.

**Carried forward (binding).** `D-043` and `D-046` both bind Step 3.5.

---

## 8. STOP GATE

**Status:** `DONE`. No repairs, no open decisions, nothing blocked.

**Report:** `docs/ai-reports/33-crmmapping-deadletter-repositories.md`

**Open decisions:** none.

**Next step:** `Lead/2.4` — cross-module adapters. `LeadCaptureOutboxAdapter`: inserts the `sync_events` row (payload includes `entity_type` and `local_entity_id`) inside the caller's transaction, with the unique idempotency key making a replay a duplicate-key outcome. `LeadMappingRelinkAdapter`: re-links the secondary's mappings to the primary; on a UNIQUE collision keeps the primary's mapping, deletes the secondary's conflicting one and logs it (A4). No transaction is opened here.
