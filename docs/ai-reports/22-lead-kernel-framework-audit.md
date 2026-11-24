# Step 0.1 — Kernel and framework audit, decision recording (Lead)

**Status:** DONE_WITH_NOTES

**Summary.** Read-only audit of the Shared kernel and the framework Schema builder against the
premises of `docs/ai-plans/lead-module-action-plan.md`. Every kernel class the plan names was
located and its exact method signatures recorded; three artifacts the plan or AGENTS.md name
(`ClockContract`, `DuplicateKeyException`, `WpQueryExceptionTranslator`) do **not** exist, and the
Schema builder does **not** emit `ENGINE=InnoDB` although rule `02:64` and the plan's S6 require it
(prefix and FK-target resolution are implemented and verified). All four migrations match plan §3.
The 27 plan decisions were recorded in a freshly restarted `DECISIONS.md` (developer chose to
recreate the trail after the working-tree purge). Four gap decisions are left OPEN for the developer.

**Inputs used.**

- `core/Shared/**` (read: contracts, `Result`, `PaginatedResult`, repositories, logger, translator)
- `framework/Database/Schema/{Schema,Blueprint}.php`, `framework/Database/Adapters/*`
- `framework/Routing/**`, `framework/Support/Facades/Route.php` (presence only)
- `app/**` (providers, `Database/WpTransactionManager.php`), `config/app.php`
- `database/migrations/2026_09_29_232811_create_leads_table.php`,
  `.../2026_11_22_210000_create_crm_mappings_table.php`,
  `.../2026_11_22_210100_create_sync_events_table.php`,
  `.../2026_11_22_210200_create_dead_letters_table.php`
- `.claude/rules/09-autonomous_execution.txt` (A9–A11 formats), `02-framework-adapter-wordpress.txt`
- `README.md:2766-2771`, `framework/Database/Query/QueryBuilderInterface.php:334-345`

**Rules and skills applied.** AGENTS §0 (pre-flight), §6 (scope, HALT format), §11 (ports/adapters);
09 A5 (loop), A9 (decisions), A10 (hard stops), A11 (reports). No skill was loaded — the step is an
analysis step with no scaffolding or delivery work. Tests paused per AGENTS §6.

---

## 1. Kernel checklist (Step 0.1 DO item 1)

| Artifact | Present | Exact signature / location |
| --- | --- | --- |
| `Result` | ✅ | `final readonly class` — `core/Shared/Application/DTOs/Result.php:62`. `success(mixed $data = null, string $message = '')` `:145`; `failure(string $message, ?string $errorCode = null)` `:205`; `ok()` `:124`; `notFound()` `:218`; `conflict()` `:247`; `validation()` `:262`; `status()` `:406`; error-code constants `:84-92`. |
| `PaginatedResult` | ✅ | `core/Shared/Domain/DataObjects/PaginatedResult.php:36`; `fromSlice(iterable, int, int = 15, int = 1)` `:129`; `empty()` `:140`; `meta()` `:313`; `MAX_PER_PAGE = 1000` `:46`. |
| `TransactionManagerContract` | ✅ | `core/Shared/Domain/Contracts/TransactionManagerContract.php:43`; `run(callable): mixed` `:73`; `transaction()` `:57`; `beginTransaction()/commit()/rollback()/inTransaction()` `:82-108`. |
| `EventDispatcherContract` | ✅ (contract only) | `.../EventDispatcherContract.php:10`; `dispatch(object $event): void` `:15`. **Not bound anywhere; no adapter exists** (former `LaravelEventDispatcher` was deleted in the purge). |
| `QueueManagerContract` | ✅ (contract only) | `core/Shared/Application/Contracts/QueueManagerContract.php:8`; `push(string $jobIdentifier, WriteDTO $data): void` `:16`. Not bound. Unused by design (DP-3/D-010). |
| `TranslatorContract` | ✅ | `.../TranslatorContract.php:14`; the method is **`translate(string $key, array $replace = []): string`** `:24` — **not** `get()`. Adapter `WpTranslator` `core/Shared/Infrastructure/Localization/WpTranslator.php:18`; bound at `app/Providers/RepositoryServiceProvider.php:57-62`. |
| `ClockContract` | ❌ **MISSING** | No file under `core/Shared/Domain/Contracts/`; repo-wide grep for `ClockContract` → 0 hits. See D-024. |
| `LoggerInterface` binding | ✅ | Bound to `WpLogger` (`core/Shared/Infrastructure/Logging/WpLogger.php:21`, PSR-3 `AbstractLogger`) at `app/Providers/RepositoryServiceProvider.php:73-78`; threshold from `config('app.log_level')`. |
| `AbstractWpRepository` | ✅ | `core/Shared/Infrastructure/Repositories/AbstractWpRepository.php:25` — exactly `db(): wpdb` `:73`, `tableName(string)` `:43`, `escapeIdentifier(string)` `:55` (matches D4). |
| `BaseWriteRepository` | ✅ | `.../BaseWriteRepository.php:20` — `insert()` `:93`, `write()` `:144`, `deleteRecord()` `:206`, `findRecordForUpdate()` `:247`, `rowExists()` `:280`, **`now(): string` (UTC)** `:313`, `executeQuery()` `:51`, `duplicateException()` `:77`, `throwIfDuplicate()` `:408`. |
| `BaseReadRepository` | ✅ (not empty) | `.../BaseReadRepository.php:21` — `findRecordById()` `:33`, `rowExists()` `:62`. AGENTS D4's "currently empty" is stale (D-027). |
| `WpQueryExceptionTranslator` | ❌ **MISSING** | Repo-wide grep → 0 hits. Duplicate detection lives in `BaseWriteRepository::throwIfDuplicate()` `:408`. See D-025. |
| `DuplicateKeyException` | ❌ **MISSING** | Repo-wide grep → 0 hits. Modules supply their own exception via the `duplicateException()` hook (e.g. `core/Company/Domain/Exceptions/CompanyDuplicateException.php`). See D-025. |
| `SlugGeneratorContract` | ❌ (different name) | The port is `SlugCheckerContract` (`.../SlugCheckerContract.php:11`); `SlugGenerator` is concrete (`core/Shared/Infrastructure/Utilities/SlugGenerator.php:18`). Plan marks it report-only; not needed here. See D-027. |

**Composition root (for Steps 0.2/0.3/4.1):**

- `app/Providers/AppServiceProvider.php` is a **stub** — `register()` holds one commented-out
  example (`:12`), `boot()` is empty. The plan's shared binding (`UuidGeneratorContract`, and
  `ClockContract` per D-024) belongs here and nothing occupies it.
- `app/Providers/RepositoryServiceProvider.php` binds the shared adapters:
  `TransactionManagerContract`→`WpTransactionManager` (singleton, `:30-33`), `RateLimiterContract`,
  `TranslatorContract`, `LoggerInterface`, `CacheInterface` (`:38-96`).
- `config/app.php:47-83` lists the providers. **No `LeadServiceProvider` / `CrmSyncServiceProvider`**
  yet (expected — Steps 0.2/0.3 create them).
- No CRM config keys exist yet (base URL, timeouts, batch size, webhook secret) — Step 4.1 adds them.

## 2. Schema-builder verification (Step 0.1 DO item 2 — S6)

| S6 sub-check | Verdict | Evidence |
| --- | --- | --- |
| Table **prefix** applied to the created table | ✅ FOUND | `Schema::create()` `framework/Database/Schema/Schema.php:214` → `$adapter->tableName($table)`; `WpDatabaseAdapter::tableName()` `framework/Database/Adapters/WpDatabaseAdapter.php:92-102` prefixes with `$wpdb->prefix`, avoiding double-prefix. |
| Table **prefix** applied to foreign-key **targets** | ✅ FOUND | `Schema::resolver()` `Schema.php:426` → `$adapter->tableName($table)`; `Blueprint::constrained()` `framework/Database/Schema/Blueprint.php:1791` → `resolve()` `:1826`. |
| `foreignId()->constrained()` target resolution for **`users`** | ✅ FOUND | `leads` migration `database/migrations/2026_09_29_232811_create_leads_table.php:53-56` (`foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete()`) → resolves to `wp_users`. |
| `foreignId()->constrained()` target resolution for **`sync_events`** | ✅ FOUND | `dead_letters` migration `.../2026_11_22_210200_create_dead_letters_table.php:32-40` (both FKs) → resolve to `wp_sync_events`. |
| **`ENGINE=InnoDB` emitted** | ❌ **MISSING** | Nothing emits it: `Blueprint::toCreateSql()` `Blueprint.php:1892` renders `CREATE TABLE \`%s\` (...)` + optional charset clause; `WpDatabaseAdapter::charsetCollation()` `WpDatabaseAdapter.php:428` returns `$wpdb->get_charset_collate()` (charset/collate only); `createTable()` `:215` → `throughDbDelta()` `:952` → `dbDelta()`. Repo-wide `ENGINE=` grep outside `vendor/` → 0 emitted clauses. Contradicts `README.md:2770` and `framework/Database/Query/QueryBuilderInterface.php:345`; required by `.claude/rules/02-framework-adapter-wordpress.txt:64`. See D-026. |

**Consequence for the plan.** Tables created without an engine clause inherit the server's
`default_storage_engine`, which is InnoDB on supported MySQL (5.5+) and MariaDB (10.2+), so the
locking/transaction steps (2.2 reaper, `findByIdForUpdate`, transactional outbox/DLQ) hold in
practice. The developer should confirm `SELECT @@default_storage_engine;` before Phase 2; if it is not
InnoDB, D-026 Option 2 (a JOB F framework change) becomes a prerequisite.

## 3. UUID check (Step 0.1 DO item 3 — A3)

No `UuidGeneratorContract` and no uuid utility exist (repo-wide grep for `UuidGenerator`/`uuid`
generation in `core/`, `app/` → 0 hits). **Expected**: A3/D-003 has Step 0.2 create them additively.
No conflict with an occupied path.

## 4. Schema facts (Step 0.1 DO item 4 — plan §3)

All four migrations read in full; every fact in plan §3 confirmed (see `DECISIONS.md` D-017…D-023 for
per-rule evidence): `idempotency_key` varchar(191) UNIQUE; `payload_json` NOT NULL `longText`;
`status` default `pending`; `attempts` `unsignedTinyInteger` default 0 and **no** `max_attempts`
column and **no** lead column; `next_attempt_at` NOT NULL; both `crm_mappings` UNIQUE keys (4-part
local, 3-part remote); `dead_letters` has no `created_at`/`updated_at`.

---

## Files

**Created**
- `docs/ai-reports/DECISIONS.md` — fresh decision trail; D-001…D-027 (A/DP/S transcriptions + 4 gap entries).
- `docs/ai-reports/PROJECT-LOG.md` — fresh log; header + the `Lead/0.1` row.
- `docs/ai-reports/22-lead-kernel-framework-audit.md` — this report.

**Modified:** none. **Deleted:** none.

**Not touched (scope):** `core/`, `app/`, `framework/`, `database/`, `routes/`, `config/`, `vendor/` —
no file created or edited there. No migration created or edited.

## Self-check

| Check | Result | Evidence |
| --- | --- | --- |
| C1 planned files exist | PASS | Three `docs/ai-reports/` files created; `ls docs/ai-reports/` lists them. No unexpected file. |
| C2 scope audit vs brief/A4 | PASS | Writes limited to `docs/ai-reports/**` (A4 allows). `git status --porcelain docs/` adds only the three new files. |
| C3 syntax (`php -l`) | N/A | No PHP file created or modified. |
| C4 `composer dump-autoload` | N/A | No new namespace/class. |
| C5 Definition of Done walk | PASS | Step 0.1 ACCEPTANCE = "report with the kernel checklist, the Schema-builder findings and the decision entries" → sections 1–4 + `DECISIONS.md`. Rule DoDs (01 P6, 04, 05 R11, 06 T10) do not apply (no Entities/Services/ports written). |
| C6 06 T8 greps | N/A | `core/` unchanged by this step (report-only). |
| C7 wiring | N/A | No new Contract created, so none to bind. Provider inventory recorded for Steps 0.2/0.3. |
| C8 schema fidelity | PASS | Four migrations read; plan §3 facts confirmed with `path:line` (D-017…D-023). No schema invented. |
| C9 leftovers | PASS | No `dd`/`dump`/`var_dump`; no half-finished TODOs. |
| C10 runtime check | N/A | Step is read-only analysis; the brief calls no runtime check. Engine confirmation is delegated to the developer (D-026). |
| C11 tests | Paused per AGENTS.md §6 — none written, none claimed. |
| C12 transactions | N/A | No code. |
| C13 boundaries | N/A | No code; provider/binding inventory recorded only. |
| C14 file existence | PASS | `docs/ai-reports/` verified absent (`ls docs/`) before creating; no occupied path overwritten. |
| C15 rule-to-skill sync | N/A | No AGENTS.md / rule / skill edit, so no A5b sweep. |
| C16 repairs | N/A | No A6b round ran — no self-check failed. |

## Commands run

`git check-ignore -v .claude/rules .claude/skills docs/ai-reports` (guard, no output → pass);
`git status --porcelain [paths]`; `git ls-files`; `git show HEAD:docs/ai-reports/{DECISIONS,PROJECT-LOG}.md`;
`ls`, `find`, `grep -rn`/`sed -n` for the reads above. No `php -l` (no PHP written), no migrations, no
test run, no git write operation, no DB access.

## Decisions

27 total in `DECISIONS.md`: **D-001…D-007** (A1–A7, DECIDED), **D-008…D-016** (DP-1…DP-9 incl. DP-4,
DECIDED), **D-017…D-023** (S1–S7, VERIFIED), and four **OPEN** gap decisions —
**D-024** `ClockContract` missing, **D-025** `DuplicateKeyException`/`WpQueryExceptionTranslator`
missing, **D-026** `ENGINE=InnoDB` not emitted, **D-027** doc drift (`SlugGeneratorContract`,
"empty" `BaseReadRepository`, `WpTransactionManager` location).

## Deviations

1. **Pre-flight HALT before this step (09 A10 H1), resolved by the developer as Option B.** The
   working tree had 101 uncommitted deletions, including all of `docs/ai-reports/`, so §0 CONTEXT
   HYDRATION could not read `DECISIONS.md`/`PROJECT-LOG.md`. The developer confirmed the purge was
   intentional and authorized recreating the trail fresh; hence `DECISIONS.md` restarts at `D-001`.
2. **Report numbering continues at `22`** rather than restarting at `01`, because only `DECISIONS.md`
   and `PROJECT-LOG.md` were specified to restart; reports `01`–`21` remain in git history and the
   numbering convention (NN-slug) is unchanged. Recorded here rather than as a D entry (A9: don't
   record convention-following names).
3. **D-026 left OPEN, not raised as H12.** Rationale in `DECISIONS.md` D-026: the plan needs InnoDB
   *semantics* (delivered by the server default), not the emit itself, so no framework change is
   required to complete the plan; the developer decides at the STOP GATE.

## Deferred and open items

- **D-024** confirm the time source for Steps 1.2/3.1 (`ClockContract` additive port vs Delivery-provided `DateTimeImmutable`).
- **D-025** confirm the duplicate-key mechanism (module conflict exception via `duplicateException()`).
- **D-026** confirm `default_storage_engine` is InnoDB; decide whether a JOB F task should emit `ENGINE=InnoDB`.
- **D-027** schedule the AGENTS.md/plan wording corrections.
- No `EventDispatcherContract` adapter is bound; the plan raises Domain Events only if a behaviour method produces one (Step 1.2 / 08 E3). If Step 1.2 does raise events, an additive `WpEventDispatcher` + binding becomes necessary and will be recorded then.

## Tests

Paused per AGENTS.md §6.

## Next step

**Step 0.2 — Scaffold module `Lead` (03, FULL-BASELINE) + `UuidGeneratorContract`**, after the
developer resolves D-024 (time source) and confirms D-026 (storage engine), since both touch what
Step 0.2 creates/binds.

---

### STOP GATE

- **Step:** `Lead/0.1` — **Status: DONE_WITH_NOTES**
- **Report:** `docs/ai-reports/22-lead-kernel-framework-audit.md`
- **Open decisions:** D-024 (ClockContract — affects Step 0.2's file list), D-025 (duplicate-key mechanism), D-026 (**confirm `SELECT @@default_storage_engine;` is InnoDB** — gates Phase 2/3), D-027 (doc drift).
- **Next step:** `0.2` — Scaffold the `core/Lead/` tree + `UuidGeneratorContract`/`WpUuidGenerator` and `LeadServiceProvider`.
- **Awaiting:** the developer's confirmation of the four OPEN decisions above. Nothing is started until then.

**Resolution (2026-11-23).** All four decisions were confirmed by the developer and are now `DECIDED` in `DECISIONS.md`: D-024 (additive `ClockContract` + `WpClock`), D-025 (module conflict exception via `BaseWriteRepository::duplicateException()`; no global translator), D-026 (storage engine confirmed InnoDB by the developer — no framework change), D-027 (follow the actual codebase names/paths; doc corrections logged for Step 5.3). Step 0.2 then ran — see `23-lead-module-scaffold.md`.
