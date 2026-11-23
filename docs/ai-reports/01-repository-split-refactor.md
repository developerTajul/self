# Step 01 — Repository Split Refactor (`core/01-repository-split-refactor`)

**Status:** DONE_WITH_NOTES
**Date:** 2026-11-15
**Branch:** `wpdb`
**Plan:** `docs/ai-plans/ai_prompt_update_repository_rules.md`

## Summary

Split the single `BaseRepository` into three classes — `AbstractWpRepository` (connection
layer only), `BaseWriteRepository` (write helpers), `BaseReadRepository` (empty; read
capability without write capability) — so the CQRS boundary is enforced by inheritance
rather than by discipline. `WpCompanyWriteRepository` and `WpCompanyReadRepository` were
re-pointed at the new bases; the read adapter's three duplicated helpers (`db()`,
`tableName()`, `escapeIdentifier()`) were deleted in favour of the inherited ones, and a
dead `$db` local found by the IDE linter was removed. Four rule files and `AGENTS.md` §4
D4, §9 and §14 were updated to match.

The step is DONE_WITH_NOTES rather than DONE for one reason: the brief's instruction to
"fix the 5 dead adapters" assumed a constructor fix, but those classes are Eloquent
implementations with no Eloquent available (see Deviations). They were re-pointed at
`BaseWriteRepository` so no dangling reference remains, but their bodies stay dead. The
developer chose "skip them, ship the rest" when this was raised.

## Inputs used

`docs/ai-plans/ai_prompt_update_repository_rules.md`; `AGENTS.md` §4/§9/§14; rules
`09` (autonomous execution), `03`, `04`, `06`; the six live `*WriteRepository`
adapters; `core/Company/Infrastructure/Repositories/*`; `tests/manual/probe-company-*`.

## Rules and skills applied (PROCESS GUARD, before coding)

- §2 MODE DETECTION — **ADAPTER** for every changed path under `core/Shared/Infrastructure`
  and `core/*/Infrastructure`. No CORE-PURE path touched; no `Domain`/`Application` file edited.
- §6 FILE EXISTENCE GUARD — all three new paths verified `NOT TRACKED` before creation.
- §6 INHERENT WIRING — no provider change was needed; the Company bindings resolve the same
  classes by contract, and constructors stayed signature-compatible except for the base.
- §7 RICH DOMAIN MODEL — no Entity, Value Object or Domain Event touched.
- §9 CQRS PORT SPLIT — the object of the step; `BaseReadRepository` inherits no write method.
- §10 TRANSACTIONS — unchanged; no transaction boundary was added, moved or removed.
- §11 PORTS AND ADAPTERS — adapters still hold no business logic and leak no framework type.
- §4 D4 — updated by explicit instruction in the brief (see Decisions).
- 09 A5 S1/S2/S3/S4; A6 C1-C14; A7 (only `php -l`, `composer dump-autoload`, read-only greps
  and the project's own probes were run); A9 (Tier 2/3 decisions recorded below).
- Tests: **Paused per AGENTS.md.** No test written, scaffolded or run; `tests/` untouched.
  The probe scripts are the project's existing manual probes, plus three throwaway probes
  in `.probe-tmp/` (not committed, not part of the suite).

## Files

### Created

| File | Purpose |
| --- | --- |
| `core/Shared/Infrastructure/Repositories/AbstractWpRepository.php` | Connection layer only: `db()`, `tableName()`, `escapeIdentifier()`. No read, no write method. |
| `core/Shared/Infrastructure/Repositories/BaseWriteRepository.php` | Write helpers, moved from `BaseRepository`, plus new `deleteRecord()` and `findRecordForUpdate()`. |
| `core/Shared/Infrastructure/Repositories/BaseReadRepository.php` | Deliberately empty; grants the connection layer with no write method in the chain. |

### Deleted

| File | Reason |
| --- | --- |
| `core/Shared/Infrastructure/Repositories/BaseRepository.php` | Renamed to `BaseWriteRepository` per the brief; no other class named it. |

### Modified

| File | Change |
| --- | --- |
| `core/Company/Infrastructure/Repositories/WpCompanyWriteRepository.php` | `extends BaseWriteRepository`; `findByIdForUpdate()` and `delete()` now call the new base helpers instead of their own inline SQL. |
| `core/Company/Infrastructure/Repositories/WpCompanyReadRepository.php` | `extends BaseReadRepository`; removed the duplicated `db()`, `tableName()` and `escapeIdentifier()`; ctor now calls `parent::__construct($wpdb)`; removed a dead `$db` local in `activitiesGroupedByDeal()`. |
| `core/Activity/…/ActivityWriteRepository.php` | `extends BaseWriteRepository` + import repointed (body unchanged — dead). |
| `core/Auth/…/AuthWriteRepository.php` | Same. |
| `core/Contact/…/ContactWriteRepository.php` | Same. |
| `core/Deal/…/DealWriteRepository.php` | Same. |
| `core/Pipeline/…/PipelineWriteRepository.php` | Same. |
| `core/Lead/…/LeadNoteWriteRepository.php` | Two stale docblock paragraphs corrected: they justified not extracting a shared base "because it would be a shared ancestor the read repository inherits without needing it" — exactly what this split now solves. |
| `core/Lead/…/LeadConversionWriteRepository.php` | One stale docblock sentence naming `BaseRepository` corrected. |
| `AGENTS.md` | §4 D4 (three base classes), §4 D6, §9 adapter row, §9 text below the table, §14 checklist. |
| `.claude/rules/03-module-scaffolding.txt` | Tree, the `BASEREPOSITORY` section rewritten as three classes, templates, the load line and the EXEMPT list. |
| `.claude/rules/04-crud-pipeline.txt` | Load line, WRITE/READ lines, ROW LOCK line, DoD checklist item. |
| `.claude/rules/06-ports-and-portability.txt` | Table T2 (both rows, WordPress and Eloquent columns), DoD item. |

## Self-check (C1-C14)

| Check | Result | Evidence |
| --- | --- | --- |
| C1 planned files exist, no unexpected file | PASS | `git status --short`: exactly the 3 created, 1 deleted, 9 modified code files + 4 doc files. |
| C2 scope audit | PASS | Every changed path is `core/*/Infrastructure`, `core/Shared/Infrastructure`, or a brief-named rule file. See Deviations for the two docblocks and the 4 unnamed rule files. |
| C3 syntax | PASS | `php -l` clean on all 9 changed/created PHP files. |
| C4 `composer dump-autoload` | PASS | "Generated optimized autoload files containing 2146 classes". |
| C5 rule DoDs walked | PASS | §9 CQRS split verified by reflection (below); §14 checklist items re-read against code. |
| C6 `core/` changed → T8 greps | PASS | T8-A: zero real WP/Framework/App **imports** in Domain/Application (only docblock prose matches). T8-B: zero `Framework\Database` in `core/`. |
| C7 wiring | PASS | No new Contract; both Company bindings unchanged and still resolve (probes green). |
| C8 schema fidelity | PASS | No schema, column or Entity field touched. |
| C9 no leftovers | PASS | No `dd`/`dump`/`var_dump`/unfinished TODO added. Probe scratch lives in uncommitted `.probe-tmp/`. |
| C10 runtime check | PASS | Project probes run (results below). No data created; probes use `FakeWpdb`. |
| C11 tests | PASS | "Paused per AGENTS.md" — none written, scaffolded or run. |
| C12 transactions | PASS | No transaction boundary added, moved or removed; `delete()`/`findByIdForUpdate()` keep their in-service transaction contract. |
| C13 boundaries | PASS | Delivery still injects contracts; no persistence exception in a Service; no `Framework\Database` in repositories. |
| C14 file existence verified beforehand | PASS | `git ls-files --error-unmatch` returned NOT TRACKED for all three new paths. |

### Verification actually run

| Check | Result |
| --- | --- |
| Hierarchy probe (reflection, 22 assertions) | ALL PASS — `BaseWriteRepository`/`BaseReadRepository` both extend `AbstractWpRepository`; `AbstractWpRepository` is abstract and owns `db()`/`tableName()`/`escapeIdentifier()`; neither child re-declares them; both Company adapters extend the right base and no longer declare the three helpers. |
| **CQRS boundary probe** | PASS — `BaseReadRepository`'s full inherited chain exposes **no** `insert`, `write`, `deleteRecord`, `executeQuery` or `findRecordForUpdate`. |
| Signature probe | PASS — `deleteRecord(int $id, string $table, string $action): bool`; `findRecordForUpdate(int $id, string $table, string $action): ?array`. |
| `deleteRecord` runtime probe (5 checks) | ALL PASS — removes a row; `false` for a missing row; **throws** on a failed statement rather than reporting `false`; message names the action and carries the driver text. |
| `findRecordForUpdate` compiled SQL | ALL PASS — `SELECT * FROM \`wp_widgets\` WHERE \`id\` = 42 LIMIT 1 FOR UPDATE`; prefix applied, id bound as an integer, no placeholder left unbound. |
| `findRecordForUpdate` execution against `FakeWpdb` | **NOT VERIFIED** — `FakeWpdb`'s SELECT-tail parser has no `FOR UPDATE` clause, so the statement cannot run against the fake. Pre-existing limitation; the shipped `WpCompanyWriteRepository` used the identical spelling. |
| `probe-company-update-persistence` | 40 probes, 0 failed (unchanged from baseline) |
| `probe-company-update-flow` | 31 probes, 0 failed |
| `probe-company-delete-flow` | 21 checks, 0 failed |
| `probe-company-admin-urls` | 10 probes, 0 failed |
| `probe-company-pagination-clamp` | 7 failed — pre-existing |
| `probe-company-index-view` | 7 failed — pre-existing **and re-measured** |
| `probe-company-store-validation` | 6 failed — pre-existing |
| `probe-company-store-dispatch` | 2 failed — pre-existing |

**`probe-company-index-view` was re-measured against the claim in the earlier report.** That
report recorded 4 failures; it now reports 7, all in edit-link/URL-rendering assertions that
touch no repository. Verified pre-existing by `git stash push -- core/` and re-running: **7
failed with and without this step's changes.** The earlier report's figure was itself stale;
this step changed the number by zero.

## Decisions

### D-2026-11-15-01 — the five dead adapters are re-pointed but not repaired

**Type:** RULE CONFLICT (brief vs. A10/H2).
**Situation:** the brief says to "fix the 5 dead adapters" by removing the Eloquent model from
their constructors. They are not constructor-broken: their bodies are Eloquent calls
(`fill()`, `save()`, `toArray()`, `refresh()`, `DB::transaction()`, pivot
`syncWithoutDetaching()`), and both `app/Models/` and `vendor/illuminate/` **do not exist**.
Removing the ctor argument leaves five classes that still reference five unresolvable model
classes and an uninstalled package.
**Options:** (a) rewrite all five as `$wpdb` adapters; (b) re-point the inheritance only, leave
the bodies dead; (c) delete them; (d) leave them entirely untouched.
**Decision:** (b), after the developer chose "skip them, ship the rest". Their `extends` and
`use` now name `BaseWriteRepository` so the rename leaves **no dangling reference** — without
this the rename would have made them fail at class-load instead of at first construct,
widening the failure. Bodies unchanged.
**Basis:** A9 precedence 1 (the developer's explicit choice when the conflict was raised) over
A10-H2; §6 SCOPE forbids inventing five query implementations the brief never described.
**Impact:** five files, 2 lines each, fully reversible. No runtime change — nothing binds them.
**Proposed AGENTS.md change:** none.
**Status:** OPEN (the real repair is a separate JOB C task, one module at a time).

### D-2026-11-15-02 — `AbstractWpRepository` replaces `BaseRepository` as the D4 base

**Type:** RULE CONFLICT (§4 D4 vs. the brief).
**Situation:** §4 D4 read "the abstract base stays `BaseRepository`"; the brief renames it and
installs `AbstractWpRepository` as the root.
**Decision:** followed the brief. D4 rewritten to name all three classes.
**Basis:** A9 precedence 1 — the brief names the change explicitly, and A16's "no rule file
edited" is overridden by the brief authorising exactly these four files.
**Impact:** `AGENTS.md` §4, §9, §14 + `03`/`04`/`06`; reversible.
**Status:** OPEN (developer to confirm D4's new wording).

### D-2026-11-15-03 — `$table` is a per-call argument, not a constructor property

**Type:** GAP.
**Situation:** the brief's new-method signatures take `string $table` per call. The old `03`
rule described a base whose ctor took `$table` once and held it (`protected readonly string
$table`), an API the real `BaseRepository` never had.
**Decision:** kept the brief's per-call shape, which is what the live code already did
(`$this->insert($values, self::TABLE_SUFFIX, $action)`), and rewrote the `03` section to
describe the classes as they actually are.
**Basis:** A9 precedence 5 (existing codebase convention, verified by reading) + the brief.
**Impact:** `03` only. Reversible.
**Proposed AGENTS.md change:** none.
**Status:** OPEN.

### D-2026-11-15-04 — four rule files still name `BaseRepository`

**Type:** RULE ERROR.
**Situation:** `02-framework-adapter-wordpress.txt` (lines 27, 41, 63, 65, 77),
`05-rich-domain-and-services.txt` (136, 139), `09-autonomous_execution.txt` (C13) and
`11-fluent-database.txt` (2, 8, 12) still reference the now-deleted class.
**Decision:** **not edited.** The brief named exactly four files; A2.5 forbids editing rules
it did not name. Recorded here so a future step fixes them.
**Basis:** A2.5 / §6 SCOPE.
**Impact:** those four files now contain a dangling class name. **This is the one known
inconsistency left by the step** and the reason the status is DONE_WITH_NOTES.
**Proposed AGENTS.md change:** none — the fix belongs in a follow-up brief naming those files.
**Status:** OPEN — **needs a follow-up step.**

## Deviations

1. **The 5 dead adapters were re-pointed, not repaired** (D-2026-11-15-01). The brief's
   premise for this item did not match the code.
2. **Two Lead docblocks were corrected** (`LeadNoteWriteRepository`, `LeadConversionWriteRepository`).
   Outside the brief's file list, but both stated as fact that a shared base could not be
   extracted — a claim this step falsified. Leaving them would have left two files documenting
   a design constraint that no longer exists. Reversible; flagged for review.
3. **One dead `$db` local removed** in `WpCompanyReadRepository::activitiesGroupedByDeal()`.
   Surfaced by the IDE linter once `db()` became inherited; the variable was genuinely unused.
4. **A `RuntimeException` import remains used** in `WpCompanyReadRepository` — checked, not
   removed, because six other call sites still throw it.
5. **`probe-company-index-view` baseline re-measured** at 7 failures (earlier report said 4);
   confirmed pre-existing by stashing this step's changes.

## Deferred and open items

- The five dead adapters (`Activity`/`Auth`/`Contact`/`Deal`/`Pipeline` `*WriteRepository`)
  are still non-functional. Real repair = per-module `$wpdb` rewrites; `ContactWriteRepository`
  needs pivot and transaction semantics rewritten by hand.
- Four rule files still name the deleted `BaseRepository` (D-2026-11-15-04).
- `findRecordForUpdate()` is unverified at runtime — `FakeWpdb` cannot parse `FOR UPDATE`.
  Verifying it needs a real database.
- `BaseReadRepository` is intentionally empty; shared read helpers (`paginateRecords`,
  `fetchAllLatest`) were NOT added — the brief said "minimal base class for now", and the
  live read adapters already carry their own working pagination, so adding them would be
  unexercised code.
- `README.md` was not updated; it is not a binding rule file and the brief did not name it.

## Tests

Paused per AGENTS.md. No test written, scaffolded, required or run.

## Next step

Fix the four rule files still naming `BaseRepository` (D-2026-11-15-04), or begin the
per-module repair of the five dead adapters.
