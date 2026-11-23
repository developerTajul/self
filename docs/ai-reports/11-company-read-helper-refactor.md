# Step 11 - Company Read Helper Refactor (Company/01)

**Status:** DONE_WITH_NOTES  
**Date:** 2026-11-15  
**Branch:** `wpdb`  
**Plan:** `docs/ai-plans/company/step_brief.md`  
**Previous step:** `Shared/01` (`docs/ai-reports/10-shared-read-repository-helpers.md`)

## Summary

Refactored `WpCompanyReadRepository::findById()` to call the inherited shared `findRecordById()` helper, eliminating its manual single-row `$wpdb` lookup while preserving mapper conversion and the read-only contract. The adapter contains no manual existence-check lookup, so the second acceptance item is satisfied vacuously. PHP lint and patch checks passed. The required T8 scans remain not clean because of pre-existing matches outside this step’s scope, including the known dead Eloquent/Laravel-shaped adapters; those were not touched.

## Inputs used

- `docs/ai-plans/company/step_brief.md`
- `AGENTS.md` §§0, 2, 6, 9, 11, 13–14
- `.claude/rules/01-universal-philosophy.txt` P1, P3–P6
- `.claude/rules/02-framework-adapter-wordpress.txt` §7
- `.claude/rules/04-crud-pipeline.txt` STEP 2
- `.claude/rules/06-ports-and-portability.txt` T1, T2, T8, T10
- `.claude/rules/09-autonomous_execution.txt` A3–A7, A9–A11, A14, A16
- Last three applicable decision entries, latest `PROJECT-LOG.md` entry, and `10-shared-read-repository-helpers.md`

## Mode and applicable rule sections

**Mode:** ADAPTER (`core/Company/Infrastructure`). Applicable: AGENTS.md §§2, 6, 9, 11, 13–14; rules 01 P1/P3–P6, 02 §7, 04 STEP 2, 06 T1/T2/T8/T10, and 09 A3–A7/A9–A11/A14/A16. No Domain/Application, Shared artifact, delivery, framework, vendor, schema, or test file was changed. D-2026-11-15-22 remains narrow to the prior Shared base and does not authorize any further Shared edit here.

## Files

- **Modified** `core/Company/Infrastructure/Repositories/WpCompanyReadRepository.php` — replaced `findById()`’s manual prepared company lookup with `$this->findRecordById($companyId, self::TABLE_SUFFIX)` and retained `CompanyEntityMapper::toEntity()` conversion.
- **Created** this report; path confirmed absent before creation.
- **Modified** `docs/ai-reports/PROJECT-LOG.md` — records this step.
- **Not modified:** `WpCompanyWriteRepository`, `CompanyQueryContract`, Entity/Application files, `CompanyEntityMapper`, `BaseReadRepository`, tests, schema, `framework/`, or `vendor/`.

## Implementation notes

- The helper uses the inherited connection layer and a prepared `SELECT * ... WHERE id = %d LIMIT 1`; it distinguishes an absent row from a database fault using `last_error`.
- The method returns `null` for an absent company and otherwise passes the raw row to `$this->mapper->toEntity($row)`, preserving the existing output shape.
- No `rowExists()` replacement was necessary: the adapter has no manual existence check. Its `is_primary` references are pivot columns, not calls to PHP `is_primary`/existence helpers.
- The adapter still extends `BaseReadRepository` and implements only `CompanyQueryContract`; no write capability was added.

## Self-check

| Check | Result | Evidence |
| --- | --- | --- |
| C1 files | PASS | Target exists; new report path was checked absent by `git ls-files docs/ai-reports/Company` / filesystem listing. |
| C2 scope | PASS | `git status --short` shows only the named target plus the prior uncommitted trail; no out-of-scope source was edited in this step. |
| C3 syntax | PASS | `php -l core/Company/Infrastructure/Repositories/WpCompanyReadRepository.php`: no syntax errors. |
| C4 autoload | N/A | No namespace/path/autoload configuration changed. |
| C5 applicable DoD | PARTIAL | The requested adapter refactor meets 04 STEP 2 read expectations, but 06 T10 requires zero T8 matches; see C6. |
| C6 Core greps | NOT CLEAN | Required T8 scans returned pre-existing matches outside this step. T8 A matched `core/Shared/Domain/Contracts/CacheInterface.php:46` (`wp_cache_get()` comment); T8 B matched `core/Shared/Domain/Contracts/EventDispatcherContract.php:15` (`dispatch` declaration). Infrastructure scan found existing `App\` imports in Activity/Auth/Contact/Dashboard/Deal/Pipeline and Shared Laravel adapters, plus `Framework\` import in `core/Lead/Infrastructure/Repositories/LeadReadRepository.php`. These files were not changed. |
| C7 wiring | N/A | No Contract/provider/route changed. |
| C8 schema fidelity | N/A | No schema or data changed. |
| C9 leftovers | PASS | No temporary/debug code introduced. |
| C10 runtime | N/A | Brief did not request a runtime check; no data created. |
| C11 tests | PASS | Tests paused per AGENTS.md; none written, scaffolded, required, or run. |
| C12 transactions | N/A | Read-only path; no transaction behavior changed. |
| C13 boundaries | PASS | Adapter remains `final`, extends `BaseReadRepository`, implements `CompanyQueryContract`, maps through `CompanyEntityMapper`, and inherits no write method. |
| C14 file existence | PASS | Existing target was named; report path was absent before creation. |
| C15 rule-to-skill sync | N/A | This step edited no AGENTS.md, rule, or skill file. |
| C16 repairs | N/A | No A6b repair round ran. |
| Tracking guard | PASS | Startup `git check-ignore -v .claude/rules .claude/skills docs/ai-reports`: no output, exit 1; quiet checks previously returned 1 for all paths. |
| Patch whitespace | PASS | `git diff --check` produced no output. |

## Commands and checks

- Startup tracking guard: `git check-ignore -v .claude/rules .claude/skills docs/ai-reports` — no output; exit 1.
- `php -l core/Company/Infrastructure/Repositories/WpCompanyReadRepository.php` — PASS.
- `git diff --check` — PASS.
- `git status --short` — confirms only the named target and pre-existing/prior-step trail.
- Rule 06 T8 scans — NOT CLEAN due to the pre-existing matches listed in C6; no unrelated cleanup performed.
- Tests paused per AGENTS.md; none run.

## Decisions

No new decision was required. D-2026-11-15-22 remains OPEN and task-specific; it was not extended to this module.

## Deviations and notes

1. The overall status is DONE_WITH_NOTES rather than DONE because the mandatory T8 audit cannot reach zero without separate out-of-scope boundary work.
2. Acceptance item 2 is vacuously met: no manual existence-check query existed in the target adapter.

## Deferred and open items

- Resolve the pre-existing T8 purity findings in their own scoped steps.
- The five dead Eloquent-shaped adapters remain a separate JOB C repair task, as recorded in earlier decisions.

## Tests

Paused per AGENTS.md.

## Next step

Stop at the end of `Company/01`; no later plan step was started.
