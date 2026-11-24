# Report 31 — Step `Lead/2.1`: Lead write + read repositories and `LeadEntityMapper`

**Step:** `Lead/2.1` | **Mode:** ADAPTER | **Status:** `DONE`
**Plan:** `docs/ai-plans/lead-module-action-plan.md` §5 Step 2.1 (line 202)
**Predecessor:** report `30` (Step 1.6)

---

## 1. §0 PRE-FLIGHT

**CONTEXT HYDRATION.** Read the last three `DECISIONS.md` entries as they stand after Step 1.6 (`D-044`, `D-045`, and `D-043`), the latest `PROJECT-LOG.md` entry (`Lead/1.6`, `DONE_WITH_NOTES`), and the previous step's report (`30-ports-contracts.md`).

**OPEN decisions touching this step:** none. `D-044` and `D-045` are `DECIDED` and both live in `LeadFilter`, which this step consumes but does not change.

**Settled decisions this step binds to (followed, not re-decided):**

- **`D-025`** — the module conflict exception is the sanctioned mechanism. `WpLeadWriteRepository` overrides `duplicateException()` and names `LeadDuplicateException`; **no global translator class is added**. The plan preamble's `WpQueryExceptionTranslator` remains stale text, per that decision.
- **`D-026`** — InnoDB semantics are relied on (verified by the developer), and no framework change is made. This step's `FOR UPDATE` read depends on that.
- **`D-043`** — carried forward to Phase 3.5 (the reaper); not this step.

**GIT TRACKING GUARD.** `git check-ignore -v .claude/rules .claude/skills docs/ai-reports` printed **nothing** (exit 1). Passed.

---

## 2. GOAL AND SCOPE

Implement the Lead module's two `$wpdb` adapters and its entity mapper, against the ports declared in Step 1.6.

**ACCEPTANCE (plan line 205):** `toEntity()` casts every column explicitly and decodes `meta_json` (S7); `null` is stored as SQL `NULL`; a UNIQUE collision becomes the module's conflict exception.

**OUT OF SCOPE:** `framework/`, `vendor/`, migrations, Phase 3 Services, Phase 4 delivery. No `tests/`.

---

## 3. FILES

| File | Action | Note |
| --- | --- | --- |
| `core/Lead/Infrastructure/Mappers/LeadEntityMapper.php` | implemented | Was a `LogicException` stub from Step 0.2 |
| `core/Lead/Infrastructure/Repositories/WpLeadWriteRepository.php` | implemented | Was a TODO stub from Step 0.2 |
| `core/Lead/Infrastructure/Repositories/WpLeadReadRepository.php` | implemented | Was a TODO stub from Step 0.2 |
| `core/Lead/Domain/Exceptions/LeadDuplicateException.php` | **created** | New; required by the acceptance and settled by `D-025` |

All three edited files already existed and are named by the plan's target tree (§4), so editing them is in scope (`AGENTS §6` file-existence guard). `LeadDuplicateException` did not exist anywhere (verified by repo-wide grep) and is the artifact `D-025` scheduled for this step.

---

## 4. WHAT WAS BUILT

**`LeadEntityMapper`.** `toEntity()` casts all thirteen columns explicitly — `(int)` for `id`/`assigned_to`, `(string)` for `uuid`, `tryFrom()` for the two enums, `DateTimeImmutable` in UTC for the timestamps. An unknown status or source value **raises rather than defaulting**; silently reading an unrecognised status as `new` would report a state nobody chose. `meta_json` decodes per S7: invalid JSON, or JSON that is not an object, raises instead of becoming `[]`, because "no metadata" and "unreadable metadata" are different facts and collapsing them would let the next write erase data. `toPersistenceArray()` encodes meta and writes an empty map as SQL `null`, so absence has one representation rather than two.

**`WpLeadWriteRepository`.** `create` / `update` / `delete` / `findByIdForUpdate`, each returning through a `readBack()` so the caller receives the database's own timestamps and id. `update` omits `created_at` — an update never re-dates the row. `duplicateException()` names `LeadDuplicateException`. `delete` raises `LeadNotFoundException` on a zero-row delete rather than reporting a silent success.

**`WpLeadReadRepository`.** `findById`, `findByEmail`, `findByPhone`, `getFilteredLeads`. The two lookups compare exact, already-normalized values so the UNIQUE indexes serve them; wrapping the column in `LOWER()` would make the predicate unindexable and turn a point lookup into a scan. `getFilteredLeads` builds one `WHERE` body from the filter and gives it to both the `COUNT(*)` and the slice, so the total can never describe a different set from the rows beneath it.

**Notable choices (mine, within the step's latitude):**

- **`ORDER BY created_at DESC, id DESC`.** Leads captured in the same second share a `created_at`; with one key MySQL may order them differently between two reads of the same page, so a row appears twice and another vanishes across pages. The `id` tiebreaker settles it.
- **`getFilteredLeads` filters `search` against `name` and `email`, escaped with `$wpdb->esc_like()`.** Without the escape a visitor typing `%` matches every lead. Verified: `%` and `_` both return 0 rather than the whole table.
- **`status` + `source` are filtered in the index's column order** (`leads_status_source_idx` is `['status','source']`). A status-and-source filter and a status-only filter can both use the index; a **source-only** filter cannot and scans. That is a deliberate trade recorded here as a limitation, not an oversight: the list offers both controls, adding a source-first index is a schema change this step must not make, and the row counts are small. Flagged for the DB Optimizer lens.
- **`meta_json` is excluded from the list projection**, matching `LeadProjectionDTO`'s own design — the list never renders it and decoding JSON per row would pay for a parse no one reads.

---

## 5. SELF-CHECK

### Static

| Check | Result |
| --- | --- |
| `php -l` on all four files | **PASS** — clean |
| T8 — no `Framework\`, `App\`, `Illuminate` import in `core/Lead/Infrastructure` | **PASS** — ZERO MATCHES |
| No `Application\Services` import in Infrastructure (`AGENTS §11`) | **PASS** — ZERO MATCHES |
| `WpLeadWriteRepository extends BaseWriteRepository`; read adapter extends `BaseReadRepository` | **PASS** — verified by reflection in the dynamic run |
| The write adapter exposes no pure read | **PASS** — verified by reflection |

### Dynamic — against the real WordPress database

A temporary script (`s21-tmp.php`) bootstrapped `wp-load.php` and exercised the mapper and both adapters end to end. **It was deleted after the run, and both temp scripts (`s16-tmp.php` from Step 1.6 and `s21-tmp.php`) are confirmed absent.** The run created one probe lead and deleted it; a follow-up count confirmed **0 residual probe rows**.

| Group | Checks | Result |
| --- | --- | --- |
| create (id, uuid, default status, source, both timestamps, meta round trip, normalized email/phone) | 9 | PASS |
| findById / findByEmail / findByPhone, including three absent-value cases | 7 | PASS |
| update (name, status, SQL `NULL` for a cleared column, `created_at` untouched, `updated_at` advanced, empty meta as `NULL` read back as `[]`) | 6 | PASS |
| duplicate key → `LeadDuplicateException` with translation key and driver exception as `previous` | 3 | PASS |
| `findByIdForUpdate` inside a real transaction, hit and miss | 3 | PASS |
| filtered pagination (type, total, projection type, raw enum values, `perPage` honoured, status filter, source filter) | 7 | PASS |
| `LIKE` wildcard escaping (`%` and `_`) | 2 | PASS |
| page past the end clamps to the last page | 1 | PASS |
| delete, then delete again → `LeadNotFoundException` | 2 | PASS |
| **Total** | **43** | **43 pass / 0 fail** |

**No repair rounds were needed.** The AUTONOMOUS FIX LOOP (09 A6b) was not entered: the first run passed every check. No Failure Signature was produced.

**200-row scan note (rule 13).** `getFilteredLeads` runs a `COUNT(*)` and a `LIMIT/OFFSET` slice over `leads`. With `n` = matching rows and `p` = page size: the count is `O(n)` on an unfiltered read, and the slice is `O(offset + p)` because MySQL must walk the rows it skips. A status-filtered read is index-served by `leads_status_source_idx`; a **source-only** read is `O(n)` as noted in §4. The `search` predicate is a leading-wildcard `LIKE` and is always `O(n)` — no index can serve it. At the module's present scale this is acceptable; if the table grows, the search is the first thing to measure.

---

## 6. RULE COMPLIANCE

| Rule | Status |
| --- | --- |
| `AGENTS §1` zone table — Infrastructure may import Core contracts and `\wpdb`, never `App\`/`Framework\` | Met |
| `AGENTS §4 D4` — `Wp` prefix; extends the correct base; no read/write method added to `AbstractWpRepository` | Met — no Shared base class was touched |
| `AGENTS §10`/plan §0.9 — no existing Shared base class or contract changed (H6) | Met — nothing under `core/Shared` edited |
| `AGENTS §11` — adapters hold no business logic; no vendor exception into Core; duplicate key via the module conflict exception (`D-025`) | Met |
| `AGENTS §9` — write adapter implements only the write port; read adapter only the read port; a class never implements both | Met |
| `02` §7.1 — every value prepared; identifiers are constants; no `%i` placeholder | Met — column lists are class constants; the one identifier passed to `findByColumn()` comes from this class's own two call sites (`email`/`phone`), never from input |
| `02` §7.3 — `findByIdForUpdate()` via `findRecordForUpdate()`, inside a transaction | Met — verified dynamically |
| `02` §7.4 — `ARRAY_A` rows cast explicitly in the mapper | Met |
| `02` §7.5 — UTC storage and reads | Met — zone set explicitly in the mapper |
| `02` §7.6 — `false` is failure, `0` rows is not; silent failure forbidden | Met — `write()`'s zero-row path uses the base's existence check; `rows()` reads `last_error` because `get_results()` returns `[]` not `false` on failure |
| `02` §7.8 — `COUNT(*)` + `LIMIT/OFFSET` → `PaginatedResult` | Met |
| `AGENTS §13` — constructor promotion, guard clauses, `final` on adapters and mappers, 1–2 line docblocks | Met |
| Plan §0.8 — no schema guessed; every column traces to the migration | Met — all thirteen columns read from `2026_09_29_232811_create_leads_table.php` |
| `AGENTS §6` — tests paused | Met — no `tests/`, no test authored or run |

---

## 7. HANDOVER NOTES

**To the DB Optimizer lens (rule 13).** Confirmed: the UNIQUE indexes on `uuid`/`email`/`phone` are relied on for the two lookups and for duplicate detection; `findByIdForUpdate()` uses `FOR UPDATE` inside a transaction and depends on InnoDB (`D-026` device-verified); `meta_json` is excluded from list reads by design. **Open concern:** a source-only filtered list cannot use `leads_status_source_idx` and scans, and a leading-wildcard search always scans. Both are recorded as accepted at present scale, not as defects.

**To the Security Auditor lens (rule 14).** Every value is bound through `prepare()`; identifiers are code constants; the `LIKE` term is escaped with `esc_like()` before binding, verified against `%` and `_` injection; the adapter raises on failure rather than serving absence. No nonce or capability concern arises at this layer — those belong to delivery (`02` §8).

**Carried forward (binding).** `D-043`: Step 3.5's reaper must catch `SyncEventTransitionException` from `releaseStaleLock()` and `markDead()` the event instead of propagating.

**Stale rule text observed (not edited — outside this step's write scope).** `02` §2.3 still lists `WpQueryExceptionTranslator` as a Shared adapter and `AGENTS §11` still names `DuplicateKeyException`; neither exists, and `D-025` settled that neither will be added. Reported here as `STALE_SKILLS_DETECTED`: **none** — a sweep of `.claude/skills/**/SKILL.md` for `WpQueryExceptionTranslator` and `DuplicateKeyException` found no hits, so no skill requires the correction this step would have had to report line by line. The stale text is in `.claude/rules/` and `AGENTS.md`, whose `D-025` proposed correction remains outstanding from Step 0.1.

---

## 8. STOP GATE

**Status:** `DONE`. No repairs, no open decisions, nothing blocked.

**Report:** `docs/ai-reports/31-lead-repositories.md`

**Open decisions:** none. `D-044`/`D-045` remain `DECIDED`.

**Next step:** `Lead/2.2` — SyncEvent write + read repositories (atomic claim, reaper). Candidate ids by the pure range scan (`status = 'pending' AND next_attempt_at <= ? ORDER BY next_attempt_at, id LIMIT ?`), then per id ONE conditional `UPDATE ... SET status='processing', locked_at=? WHERE id=? AND status='pending'`, keeping the row only when the affected-row count is exactly 1; the claim is its own short `run()` that commits before any HTTP call. The reaper locks a bounded batch of stale `processing` rows with `FOR UPDATE`, calls `releaseStaleLock()` on each and persists — catching `SyncEventTransitionException` and dead-lettering, per `D-043`.
