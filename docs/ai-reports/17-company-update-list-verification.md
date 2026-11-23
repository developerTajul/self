# Step 07 - Company Update & Paginated List Verification (Company/07)

**Status:** DONE
**Date:** 2026-11-16
**Branch:** `wpdb`
**Brief:** developer-supplied Step Brief in chat (`STEP ID: Company/07`); INPUT named `docs/ai-plans/company/api/company_module_rest_api_implementation.md` Phase 2
**Previous step:** `Company/06` (`docs/ai-reports/16-company-rest-api.md`)

## Summary

`Company/07` closes as **already-satisfied**. All four acceptance criteria were verified against source and by execution, and every one of them is met by code that `Company/06` delivered. No file was created, edited or deleted by this step. The brief's GOAL names a paginated list "with filters", but the module has no filter criteria to expose, and the SCOPE FILES did not authorize the FROZEN Domain/Application artifacts a filter would require; the developer reviewed the conflict and explicitly authorized closing the step with "(with filters)" treated as inapplicable for this module. The step is recorded so a future session reading `PROJECT-LOG.md` does not re-open it.

## Inputs used

- Developer's Step Brief for `Company/07` (in chat), including the approval message closing it under Option A
- `docs/ai-plans/company/api/company_module_rest_api_implementation.md` (Phase 2, the named input)
- `docs/ai-reports/16-company-rest-api.md` (previous step)
- `AGENTS.md` §§0, 2, 6, 9, 14
- `.claude/rules/02-framework-adapter-wordpress.txt` §6, `04-crud-pipeline.txt` STEP 1-5, `05-rich-domain-and-services.txt` R5, `09-autonomous_execution.txt` A1-A16
- Read (not edited): `core/Company/Application/Contracts/CompanyQueryServiceContract.php`, `core/Company/Application/DTOs/Requests/CompanyFilterDTO.php`, `core/Company/Application/Mappers/CompanyMapper.php`, `core/Company/Domain/ValueObjects/CompanyFilter.php`, `core/Company/Infrastructure/Repositories/WpCompanyReadRepository.php`, and the filter chain of all four other modules (`Lead`, `Contact`, `Deal`, `Activity`)

## Mode and applicable rule sections

**Mode:** DELIVERY (verification only). No CORE-PURE or ADAPTER work was performed, and no `core/` file was edited.

Applicable: AGENTS.md §§0, 2, 6, 9, 14; rules 02 §6 (delivery flow: route → middleware → controller → FormRequest → Mapper → ONE Service method → `Result` → JsonResource), 04 STEP 5 (thin controller, service contracts, guard clause), 05 R5, 09 A2.3 (verify, don't assume), A4 (FROZEN artifacts need explicit authorization), A10.

## Files

- **Created** this report; the path was confirmed absent before creation.
- **Modified** `docs/ai-reports/PROJECT-LOG.md` — records this step.
- **Modified** `docs/ai-reports/DECISIONS.md` — records D-2026-11-16-07.
- **Not modified:** every file in the brief's SCOPE FILES. `routes/api.php`, `app/Http/Controllers/Api/CompanyController.php`, `CompanyQueryServiceContract.php` and `CompanyQueryService.php` are byte-identical to their `Company/06` state. No FROZEN file, no `framework/`, no `vendor/`, no view, no provider, no test.

## Verification of acceptance criteria

| # | Criterion | Result | Evidence |
| --- | --- | --- | --- |
| 1 | `routes/api.php` registers `PUT /api/companies/{id}` and `GET /api/companies` | PASS | `routes/api.php:46` — `Route::put('companies/{id}', [CompanyController::class, 'update'])->name('api.companies.update')`; `routes/api.php:38` — `Route::get('companies', [CompanyController::class, 'index'])->name('api.companies.index')`. Both inside the `companies` group, satisfying Phase 2's "route group prefixed with `companies`". |
| 2 | `update()` accepts a valid FormRequest, creates an `UpdateCompanyDTO`, calls `updateCompany()`, returns JSON | PASS | `CompanyController.php:162-182`. Signature `update(UpdateCompanyRequest $request, int $id)`. Flow: `$request->validated()` → `$this->mapper->mapToUpdateDTO(companyId: $id, validatedData: ...)` → `updateCompany(...)` → `isFailure()` guard → `JsonResponse`. **Deviation from the brief's wording:** the response is built by `CompanyResource`, not by `CompanyResponseMapper`. See Deviations item 1. |
| 3 | `index()` creates a `CompanyFilterDTO`, calls a paginated Service method, returns a framework-agnostic paginated JSON response | PASS | `CompanyController.php:65` builds the DTO through `mapToFilterDTO()`; `:73` calls `getPaginatedCompanies($filterDTO)`; `:79-85` reads `$page->items()` and `$page->meta()` off a `PaginatedResult`. Verified by execution: the Mapper's `CompanyFilterDTO` **is** the parameter type `getPaginatedCompanies()` declares, and `PaginatedResult` imports no `Illuminate\`, `Framework\`, `App\`, `$wpdb` or `WP_` symbol. **Deviation:** the method is `getPaginatedCompanies()`, not the brief's parenthetical `getCompaniesPaginated()`. See Deviations item 2. |
| 4 | All endpoints protected by appropriate capability middleware | PASS | All six routes are inside `Route::group(['middleware' => [RequireCompanyManage::class]], ...)` (`routes/api.php:37-49`). `RequireCompanyManage extends RequireCapability` pinned to `manage_options`. |
| GOAL | "Paginated List (**with filters**)" | INAPPLICABLE | The module exposes no filter criteria: `CompanyFilterDTO` and `CompanyFilter` carry only `perPage` and `page`. Treating this as inapplicable was the developer's explicit instruction — see the decision and Deviations item 3. |

## Self-check

| Check | Result | Evidence |
| --- | --- | --- |
| C1 files | PASS | Only this report was created; its path was confirmed absent first. No planned source file existed to create. |
| C2 scope | PASS | `git status --porcelain` is byte-identical to the state at the end of `Company/06` — the same four untracked `app/` paths, the same modified `routes/api.php`, and the report trail. Nothing new appeared. No file in SCOPE FILES changed. |
| C3 syntax | PASS | `php -l app/Http/Controllers/Api/CompanyController.php` and `php -l routes/api.php` — no syntax errors. No file was edited, so this re-confirms the `Company/06` state rather than new work. |
| C4 autoload | N/A | No namespace or class path changed; no new file with a new namespace. |
| C5 applicable DoD | PASS | 02 §6 delivery flow holds at every step: route → middleware → controller → FormRequest → Mapper from a plain array → ONE Service method → `Result` → `JsonResource`, with a guard clause on `isFailure()`. 04 STEP 5 holds: the controller injects the inbound service contracts and `$mapper`, and there is no `fromArray()`. 05 R5 holds: both actions branch on `Result`. |
| C6 Core greps | PASS | No `core/` file was edited, so purity is unchanged from `Company/06`, where T8 A over `core/Company/{Domain,Application}` returned zero matches. Re-run for this step: zero matches. |
| C7 wiring | PASS | No route or binding changed. Six routes present in one capability group; verified by reading the file rather than from memory. |
| C8 schema fidelity | PASS | No schema, column, migration, DTO or filter field changed. |
| C9 leftovers | PASS | No file was edited, so no leftover can have been introduced. |
| C10 runtime | N/A | No behaviour changed and the brief did not request one. Substance was confirmed by executing the contract/mapper/PaginatedResult checks recorded under Commands. |
| C11 tests | PASS | Tests paused per AGENTS.md. None written, scaffolded, required or run. |
| C12 transactions | PASS | Unaffected — no Service or repository method changed. Writes still run in the Command Service's `executeInTransaction()`. |
| C13 boundaries | PASS | Delivery still injects service contracts, never a concrete Service. No Entity reaches the controller. No persistence exception is caught there. |
| C14 file existence | PASS | The report path was verified absent before creation. No other path was touched. |
| C15 rule-to-skill sync | N/A | This step edited no AGENTS.md, rule file or `SKILL.md`. |
| C16 repairs | N/A | No A6b repair round ran — no check failed, because no code was written or changed. |
| Tracking guard | PASS | `git check-ignore -v .claude/rules .claude/skills docs/ai-reports docs/ai-plans` printed nothing; exit 1. |

## Commands and checks

- Startup: `git check-ignore -v .claude/rules .claude/skills docs/ai-reports docs/ai-plans` — no output; exit 1.
- Acceptance sweep: `grep -n "companies" routes/api.php` — six routes; `Route::put('companies/{id}'` at `:46`, `Route::get('companies'` at `:38`.
- Middleware coverage: `sed -n '36,50p' routes/api.php` — one `Route::group` with `RequireCompanyManage` wrapping all six.
- `update()` flow read at `app/Http/Controllers/Api/CompanyController.php:162-182`.
- `index()` flow read at `:55-87`; `grep` for `mapToFilterDTO`, `getPaginatedCompanies`, `PaginatedResult`, `->meta()`.
- Method-name check: `grep -rn "getCompaniesPaginated"` — zero hits project-wide (the brief's spelling does not exist).
- Mapper check: `grep -rn "CompanyResponseMapperContract\|responseMapper" app/Http/Controllers/Api/CompanyController.php` — zero hits.
- Filter-precedent sweep: filter files of `Lead`, `Contact`, `Deal`, `Activity` read; all four carry criteria beside paging, `Company` carries none.
- Execution checks: the contract's parameter type equals the Mapper's returned `CompanyFilterDTO` (`YES`); `PaginatedResult` imports no framework/WP symbol (`NO`); `PaginatedResult::meta()` exists (`YES`).
- `php -l` on the controller and `routes/api.php` — PASS.
- `git status --porcelain` — identical to the post-`Company/06` state.
- Tests paused per AGENTS.md; none run.

## Decisions

- **D-2026-11-16-07** — `Company/07` closes as already-satisfied; "(with filters)" is inapplicable and no FROZEN artifact is touched. RULE CONFLICT.

Recorded in `docs/ai-reports/DECISIONS.md` with basis, impact and proposed AGENTS.md change.

## Deviations and notes

1. **The brief's `CompanyResponseMapper` instruction was not followed, and should not be.** Acceptance 2 asks `update()` to "return a JSON response via `CompanyResponseMapper`". That class is not a response builder: `CompanyResponseMapperContract` returns `CompanyResponseDTO` / `CompanyDetailResponseDTO` / `CompanyEditResponseDTO` — DTOs, not HTTP responses — and the Service already calls it, so `Result::getData()` carries the mapped DTO by the time the controller sees it. Routing it through the controller as well would re-map what the Service mapped and would put a presentation decision on the read path twice, against `04 STEP 4` ("one DTO serves API, Web, Console, WordPress"). The wire shape is decided once, by `CompanyResource`, which is the framework's own mechanism (02 §6 ends the delivery flow at `JsonResource`).
2. **The brief's `getCompaniesPaginated()` does not exist and was not created.** `grep` across the project returns zero hits. The real method is `getPaginatedCompanies(CompanyFilterDTO): Result` on `CompanyQueryServiceContract`, already called by the admin screen as well as the API. The brief's spelling is parenthetical ("e.g.,"), so it reads as naming a shape rather than mandating a name; adding a second method with a synonym name would be a duplicate of an existing read on the same contract.
3. **"(with filters)" is inapplicable, on the developer's explicit instruction.** `CompanyFilterDTO` and `CompanyFilter` carry only `perPage` and `page`. A sweep of every other module showed `Company` is the sole module whose filter has no criteria: `Lead` has `status`/`source`/`search`, `Contact` has `search`, `Deal` has `pipelineId`/`dealStageId`, `Activity` has six. Building one would require editing `CompanyFilter` and `CompanyFilterDTO` — both FROZEN under AGENTS.md §3 — plus `CompanyMapper` and `CompanyQueryContract`/`WpCompanyReadRepository`, none of which the SCOPE FILES named. The conflict was raised as a Structured HALT with three options; the developer selected Option A, authorized closing the step, and instructed that no filter code be written and no FROZEN file touched. That instruction was followed exactly.
4. **Status is `DONE`, not `DONE_WITH_NOTES`.** Every acceptance criterion that maps to code is verified met, no code has a known defect, and the one inapplicable goal item was settled by the developer rather than left open. Nothing is deferred to a later repair, so a note-qualified status would overstate the uncertainty.

## Deferred and open items

- **Company list filtering** remains unimplemented by design. If it is wanted later, it is a CORE-PURE + ADAPTER step in its own right (four FROZEN/Infrastructure artifacts) and needs explicit authorization of those exact files under `09 A4`.
- The endpoints have still not been exercised against the live WordPress site over HTTP; every claim in this report comes from reading source and executing framework classes outside WordPress.
- **Five anonymous test doubles** in `tests/manual/probe-company-delete-flow.php` and `probe-company-update-flow.php` remain unloadable from `Company/05`'s contract change; unchanged by this step and still reported, not repaired (tests paused).
- The two items the developer accepted as-is in `Company/06` — the bulk-delete loop (`05 R8`) and the `Result`-based not-found flow (`02 §7`) — remain as recorded there.
- The pre-existing T8 purity findings outside the Company module are unchanged.

## Tests

Paused per AGENTS.md. None written, scaffolded, required or run.

## Next step

`Company/07` is closed; the Company module's REST surface is complete. No later step was started. Any further work — list filtering, the live smoke test, or the test doubles — is the developer's to prioritise.
