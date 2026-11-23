# Step 05 - Company Atomic Bulk Delete (Company/05)

**Status:** DONE_WITH_NOTES
**Date:** 2026-11-16
**Branch:** `wpdb`
**Plan:** `docs/ai-plans/company/05-atomic-bulk-delete.md`
**Previous step:** `Company/04` (`docs/ai-reports/14-company-list-url-refactor.md`)

## Summary

Added `deleteMultiple(array $companyIds): Result` to the Company inbound write contract and implemented it in `CompanyCommandService`, which runs the whole batch inside one `executeInTransaction()` closure. `CompanyController::bulkDestroy()` now makes that single call instead of looping `deleteCompany()` per id, so the batch is atomic: a failing statement or an id matching no row rolls back the entire selection rather than leaving it half-deleted. The step resolves audit finding G-002. All three acceptance criteria are met and the interface change was verified signature-compatible across every implementer. The step is `DONE_WITH_NOTES` because the contract change makes three anonymous classes in `tests/manual/probe-*.php` unloadable, and tests are paused by policy so they were reported rather than repaired.

## Inputs used

- `docs/ai-reports/12-company-module-audit-report.md` (finding G-002)
- `AGENTS.md` §§0, 2, 6, 8, 9, 10, 11, 13, 14
- `.claude/rules/05-rich-domain-and-services.txt` R4, R5, R8, R11
- `.claude/rules/09-autonomous_execution.txt` A1-A16
- `core/Shared/Domain/Contracts/TransactionManagerContract.php` and `app/Database/WpTransactionManager.php` (read to verify the nesting guarantee, not edited)

## Mode and applicable rule sections

**Mode:** CORE-PURE (`core/Company/Application/Contracts`, `core/Company/Application/Services`) then DELIVERY (`app/Http/Controllers/Admin/CompanyController.php`), executed in that order per A5 S2.

Applicable: AGENTS.md §§0, 2, 6, 8, 9, 10, 11, 13, 14; rules 05 R4/R5/R8/R11, 06 T8 (not triggered — no Infrastructure change), 09 A1-A16.

## Files

- **Modified** `core/Company/Application/Contracts/CompanyCommandServiceContract.php` — added `deleteMultiple(array $companyIds): Result` with a docblock stating the all-or-nothing guarantee. The three pre-existing methods are unchanged.
- **Modified** `core/Company/Application/Services/CompanyCommandService.php` — added `deleteMultiple()`, which loops `$this->companyRepository->delete($companyId)` inside ONE `executeInTransaction()` call and returns `Result::success(data: null, message: …)`. No other method changed.
- **Modified** `app/Http/Controllers/Admin/CompanyController.php` — `bulkDestroy()`'s `foreach` and its `$deleted`/`$failure` accumulators were replaced by a single `deleteMultiple($ids)` call, one `isFailure()` guard, and `count($ids)` for the notice.
- **Created** this report; the path was confirmed absent before creation.
- **Modified** `docs/ai-reports/PROJECT-LOG.md` — records this step.
- **Modified** `docs/ai-reports/DECISIONS.md` — records D-2026-11-16-03.
- **Not modified:** `CompanyRepositoryContract`, `WpCompanyWriteRepository` (no bulk port method was added — see Decision), any view, `routes/web.php`, any Shared artifact, `framework/`, `vendor/`, schema, or tests.

## Implementation notes

- **One transaction, not N.** `executeInTransaction()` makes exactly one `$this->transactionManager->run()` call (`CompanyCommandService.php:230`). The repository's `delete()` issues a plain `$wpdb` query and calls no manager method, so it never opens a second frame. The batch is therefore a single `START TRANSACTION` → N `DELETE`s → one `COMMIT`, and a throw at any point rolls back every deletion in it.
- **The nesting guarantee is real, not assumed.** `WpTransactionManager::run()` (`app/Database/WpTransactionManager.php:52-75`) returns `$callback()` immediately when `$this->depth > 0`, and the binding is a `singleton` (`RepositoryServiceProvider.php:34-37`) explicitly so the depth counter is shared — its own comment says a fresh instance per resolve would issue `START TRANSACTION` inside the outer frame. The atomicity claim rests on both facts, which were read rather than presumed.
- **Why no bulk port method.** The alternative was a `deleteMany(array $ids)` on `CompanyRepositoryContract`, issuing one `DELETE ... WHERE id IN (...)`. The port speaks in single-record operations (05 R8: "Write methods and single-record loads take/return ENTITIES"), and a repository that grew a bulk method for this one caller would carry a use case's cardinality into the persistence contract. The loop stays in the Service, inside the boundary, where the cardinality decision belongs.
- **A missing row aborts the batch.** `WpCompanyWriteRepository::delete()` throws `CompanyNotFoundException` when zero rows match, so an id that vanished between page load and submission rolls the whole batch back. That is the intended semantic: a bulk delete that deletes six of eight rows and reports failure leaves stored state that contradicts what the operator was told.
- **`deleteCompany()` stays on the contract.** The single-row `destroy()` action still calls it (`CompanyController.php:261`), so removing it would break that path. Both methods are declared and both are implemented.
- **`count($ids)` is safe for the notice.** The ids come from `submittedIds()`, which validates each to a positive int and de-duplicates, so the count equals the rows the successful batch deleted.

## Self-check

| Check | Result | Evidence |
| --- | --- | --- |
| C1 files | PASS | All three SCOPE FILES exist; the report path was confirmed absent before creation. |
| C2 scope | PASS | `git status --porcelain` shows only the three named files changed by this step plus the pre-existing trail; `grep -c "^ D"` returns 0. No view, route, repository or Shared file was touched. |
| C3 syntax | PASS | `php -l` on the contract, the Service and the controller: no syntax errors. |
| C4 autoload | N/A | No namespace or class path changed; no new file with a new namespace. |
| C5 applicable DoD | PASS | 05 R5 satisfied: one `executeInTransaction()` wrapper, entity-free delete path, `Result` returned, no manual transaction call. 05 R4 satisfied: the Service still `implements` its inbound contract. §10 satisfied: every write inside `run()`, no `beginTransaction()`/`commit()`/`rollback()` in the Service. |
| C6 Core greps | PASS | T8 A/B over `core/Company/Domain` and `core/Company/Application`: no `Illuminate\`, `App\`, `Framework\`, `$wpdb`, WP symbol or global helper. The new method uses only the port, the DTO layer, the translator and `Result`. |
| C7 wiring | PASS | No route or binding changed. `deleteMultiple` is dispatched through the already-bound `CompanyCommandServiceContract`, so no new container entry is needed. |
| C8 schema fidelity | PASS | No schema or data changed. |
| C9 leftovers | PASS | No `TODO`, `FIXME`, `var_dump` or `dd()` in any of the three files. The removed `$deleted`/`$failure` accumulators are gone with no dangling reference. |
| C10 runtime | N/A | The brief did not request a runtime check; no data was created or deleted. |
| C11 tests | PASS | Tests paused per AGENTS.md. None written, scaffolded, required or run. Five pre-existing anonymous test doubles were found to be invalidated by the contract change and are **reported, not repaired** — see Deviations. |
| C12 transactions | PASS | The batch runs in exactly one `run()` call via `executeInTransaction()` (`:230`). Failure after a write is THROWN by the repository, never swallowed. No transaction API is called in the Service. `findByIdForUpdate()` is not applicable — a delete takes no lock. |
| C13 boundaries | PASS | Delivery calls ONE Service method through the contract; the Service returns a `Result` and never an Entity/row; no persistence exception is caught in the Service (`CompanyNotFoundException` keeps its existing catch in `executeInTransaction`); the Entity is untouched. |
| C14 file existence | PASS | All three targets existed and were named in SCOPE FILES; the report path was checked absent. |
| C15 rule-to-skill sync | N/A | This step edited no AGENTS.md, rule file or `SKILL.md`. |
| C16 repairs | N/A | No A6b repair round ran. |
| Contract change scan | PASS | Per A6b.6 the bare name `CompanyCommandServiceContract` was scanned tree-wide (22 hits). One production injector, `core/Lead/Application/Services/LeadConversionCommandService.php`, calls only `createCompany()` and is unaffected (`php -l` PASS). Reflection confirms `CompanyCommandService` implements all four contract methods with matching arity and return types. Five anonymous test doubles are invalidated and reported, not edited. |
| Tracking guard | PASS | `git check-ignore -v .claude/rules .claude/skills docs/ai-reports` printed nothing for them; exit 1. |
| Patch whitespace | PASS | `git diff --check` reported no whitespace errors. |

## Acceptance criteria

| # | Criterion | Result | Evidence |
| --- | --- | --- | --- |
| 1 | The contract defines `deleteMultiple(array $companyIds): Result` | PASS | `CompanyCommandServiceContract.php:53` — `public function deleteMultiple(array $companyIds): Result;` |
| 2 | The Service deletes the ids inside ONE `executeInTransaction(...)` closure | PASS | `CompanyCommandService.php:188-215`. The closure loops `$this->companyRepository->delete($companyId)` and returns one `Result`; `executeInTransaction` makes a single `run()` call at `:230`. |
| 3 | `bulkDestroy()` calls `deleteMultiple($ids)` once, replacing the loop | PASS | `CompanyController.php:304` — one call; the `foreach`, `$deleted` and `$failure` are gone. |

## Commands and checks

- Startup tracking guard: `git check-ignore -v .claude/rules .claude/skills docs/ai-reports` — no output; exit 1.
- Brief existence check: `ls -la docs/ai-plans/company/05-atomic-bulk-delete.md` — present, 1003 bytes.
- `php -l` on the contract, the Service, the controller, and `LeadConversionCommandService.php` — PASS.
- Bare-contract-name scan (A6b.6): `grep -rn "CompanyCommandServiceContract" --include=*.php .` — 22 hits, itemised in the self-check row above.
- Reflection check: `implements contract: YES`; all four methods present with matching arity (1/1) and return type (`Result`).
- Atomicity trace: one `transactionManager->run()` call in the Service; no nested manager call in the repository path.
- Leftover check: `grep -rn "TODO\|FIXME\|var_dump\|dd("` over the three files — no matches.
- `git status --porcelain | grep -c "^ D"` — 0 deletions.
- Tests paused per AGENTS.md; none run.

## Decisions

- **D-2026-11-16-03** — the batch loops the single-record port method rather than adding a bulk method to `CompanyRepositoryContract`. GAP.

Recorded in `docs/ai-reports/DECISIONS.md` with its basis, impact and proposed AGENTS.md change.

## Deviations and notes

1. **Five test doubles are invalidated by the contract change and were not repaired.** Adding `deleteMultiple` to `CompanyCommandServiceContract` makes any class that `implements` it without the method unloadable. Five anonymous classes do so, each implementing exactly the three original methods (`createCompany`, `updateCompany`, `deleteCompany`) and no more:

   | File | Line | Variable |
   | --- | --- | --- |
   | `tests/manual/probe-company-delete-flow.php` | 121 | `$recorder` |
   | `tests/manual/probe-company-delete-flow.php` | 459 | `$failing` |
   | `tests/manual/probe-company-update-flow.php` | 209 | `$recorder` |
   | `tests/manual/probe-company-update-flow.php` | 381 | `$failing` |
   | `tests/manual/probe-company-update-flow.php` | 424 | `$untouched` |

   AGENTS.md §6 TESTS PAUSED forbids writing, scaffolding or repairing tests, so these are **reported, not touched**. They are manual probes under `tests/manual/`, not part of a suite, and each needs a two-line `deleteMultiple` stub when tests are re-enabled. **This is the reason the step is `DONE_WITH_NOTES` rather than `DONE`.**

2. **`CompanyController.php` shows other changes in `git status`.** The file also carries `Company/03` and `Company/04`'s uncommitted edits. This step changed only `bulkDestroy()`.

3. **Status is `DONE_WITH_NOTES`, not `DONE`.** Every acceptance criterion passes and the code has no known defect, but the contract change knowingly leaves five test doubles unloadable, which is a real consequence a clean DONE would misrepresent.

## Deferred and open items

- The five anonymous test doubles in `tests/manual/probe-company-delete-flow.php` and `probe-company-update-flow.php` need `deleteMultiple` stubs when tests are re-enabled.
- **G-004** (REST API routes) remains open and was explicitly out of scope.
- The bulk-delete path has not been exercised through a live wp-admin submission; the atomic rollback in particular is reasoned from source rather than observed.
- The pre-existing T8 purity findings outside the Company module are unchanged.

## Tests

Paused per AGENTS.md. Five pre-existing test doubles were found invalidated by this step's contract change and are reported under Deviations; none was edited or run.

## Next step

Stop at the end of `Company/05`. No later step was started. The audit's remaining open finding is G-004 (REST routes), which this brief placed out of scope.
