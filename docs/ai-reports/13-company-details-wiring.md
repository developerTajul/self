# Step 03 - Company Details Wiring (Company/03)

**Status:** DONE_WITH_NOTES
**Date:** 2026-11-15
**Branch:** `wpdb`
**Plan:** `docs/ai-plans/company/03-wire-company-details.md`
**Previous step:** `Company/02` (`docs/ai-reports/12-company-module-audit-report.md`)

## Summary

Wired the Company Details (show) action, resolving audit findings F-001, G-001, G-003, D-001, D-002 and D-003. `CompanyController::show()` now reads an id from the request, calls `CompanyQueryServiceContract::getCompanyDetails()`, guards `isFailure()` with a redirect, and hands the extracted `CompanyDetailResponseDTO` to the view. `views/company/show.php` was rewritten to read that DTO through object properties and to render its contacts and deals, replacing a stub that read array keys the service never supplied. A hidden `company-show` admin route exposes the screen under the module's existing auth and capability middleware. All three files lint clean and the T8 scans show no new boundary leaks. The step is `DONE_WITH_NOTES` rather than `DONE` for two recorded reasons: the route path departs from the brief's literal `companies/{id}` text, and an external environment issue deleted ten tracked plan files mid-step, which were restored under explicit developer authorization.

## Inputs used

- `docs/ai-plans/company/03-wire-company-details.md`
- `docs/ai-reports/12-company-module-audit-report.md` (findings F-001, G-001, G-003, D-001, D-002, D-003)
- `AGENTS.md` §§0, 2, 6, 8, 10, 13-14
- `.claude/rules/02-framework-adapter-wordpress.txt` §§6, 7
- `.claude/rules/04-crud-pipeline.txt` STEP 5
- `.claude/rules/05-rich-domain-and-services.txt` R7, R12
- `.claude/rules/06-ports-and-portability.txt` T8
- `.claude/rules/09-autonomous_execution.txt` A1-A16
- Last three `DECISIONS.md` entries, latest `PROJECT-LOG.md` entry, and `11-company-read-helper-refactor.md`

## Mode and applicable rule sections

**Mode:** DELIVERY (`routes/web.php`, `app/Http/Controllers/Admin/CompanyController.php`, `views/company/show.php`). No CORE-PURE, ADAPTER, Shared, framework, vendor, schema or test file was changed. Applicable: AGENTS.md §§0, 2, 6, 8, 10, 13-14; rules 02 §§6/7, 04 STEP 5, 05 R7/R12, 06 T8, 09 A1-A16.

## Files

- **Modified** `app/Http/Controllers/Admin/CompanyController.php` — `show()` rewritten: signature changed from `show(string $id)` to `show(Request $request)`; the id is read through the existing `submittedId()` helper; the service is called via `getCompanyDetails()`; `isFailure()` is guarded with a redirect to `companies.index`; the extracted DTO is passed to the view. Docblock updated to explain the query-parameter addressing.
- **Modified** `routes/web.php` — added a hidden GET route `company-show` named `companies.show` inside the existing `auth` + `capability:manage_options` group, with `hidden: true` following the `companies.edit` precedent.
- **Modified** `views/company/show.php` — rewritten to read the `CompanyDetailResponseDTO` through object properties, and to render the `contacts` and `deals` collections the DTO carries. All output escaped with `e()`; `address` uses `nl2br(e())` in that order.
- **Created** `docs/ai-plans/company/03-wire-company-details.md` — the step brief, saved verbatim from the developer's message and verified on disk before execution.
- **Created** this report; the path was confirmed absent before creation.
- **Modified** `docs/ai-reports/PROJECT-LOG.md` — records this step.
- **Modified** `docs/ai-reports/DECISIONS.md` — records D-2026-11-15-24, D-2026-11-15-25 and D-2026-11-15-26.
- **Restored** ten tracked files under `docs/ai-plans/` — deleted by an external environment issue (see Deviation 2), restored with `git restore` under explicit developer authorization.
- **Not modified:** `views/company/index.php` (F-002 is out of scope), any `core/` file, any Shared artifact, `framework/`, `vendor/`, schema, or tests.

## Implementation notes

- **Id addressing.** The record is named by a query parameter (`?id=`), matching the `edit` and `destroy` handlers. WordPress picks an admin screen by matching `?page=` against the slugs it was handed at `admin_menu`, and a slug is one string with no segments, so a `/`-bearing path cannot address an admin screen. This is why the brief's literal `companies/{id}` was not used; see D-2026-11-15-24.
- **Slug collision avoided.** `CompanyController::SCREEN_SLUG` is already `companies`, so the show screen could not reuse it. The new slug `company-show` follows the module's existing `company-create` / `company-edit` naming.
- **Read path only.** The screen calls `getCompanyDetails()`, a Query Service method with no transaction, event or write call, and depends solely on the read port. No write port was reached.
- **DTO, not array.** `$result->getData()` returns the `CompanyDetailResponseDTO` built by `CompanyResponseMapper::toDetailResponseDTO()`. The view reads `$company->name` and siblings; every optional field has a `null` branch rendering `&mdash;`.
- **Money stays a string.** The deal amount renders as `amount . ' ' . currency`, never cast to float (P2).
- **Escaping.** Every interpolated value goes through `e()`; the only unescaped output is the literal `&mdash;` entity, which is template markup rather than data.

## Self-check

| Check | Result | Evidence |
| --- | --- | --- |
| C1 files | PASS | All three SCOPE FILES exist and were verified on disk; the brief and report paths were confirmed absent before creation. |
| C2 scope | PASS | `git status --porcelain` shows only the three named files plus the pre-existing trail from earlier steps; `views/company/index.php` was deliberately left untouched (F-002 is out of scope). |
| C3 syntax | PASS | `php -l` on `CompanyController.php`, `routes/web.php` and `views/company/show.php`: no syntax errors. |
| C4 autoload | N/A | No namespace, class path or autoload configuration changed. |
| C5 applicable DoD | PASS | 04 STEP 5 satisfied: the controller validates nothing itself, builds no DTO, calls ONE Service method via the injected contract, and guard-clauses `isFailure()`. 05 R7 satisfied: the view receives a ResponseDTO, never an Entity or row. |
| C6 Core greps | PASS | T8 scan over `core/Company`: `$wpdb` appears only in the two Infrastructure adapters (lines 23, 26, 88, 93, 95, 541, 647 of the read adapter and 18, 38, 43, 45 of the write adapter). Zero matches in `core/Company/Domain` or `core/Company/Application`. No image of `App\`, `Framework\`, `Illuminate\` or a WP helper in either FROZEN tree. |
| C7 wiring | PASS | The new route is registered in `routes/web.php` inside the existing group carrying `auth` and `capability:manage_options`; `companies.show` resolves to `[CompanyController::class, 'show']`. No Contract binding changed, so none was added. |
| C8 schema fidelity | PASS | No schema or data changed. The view reads only fields the DTO declares. |
| C9 leftovers | PASS | No `TODO`, `FIXME`, `var_dump`, `dd()` or commented-out code in any of the three files. |
| C10 runtime | N/A | The brief did not request a runtime check; no data was created. |
| C11 tests | PASS | Tests paused per AGENTS.md; none written, scaffolded, required or run. |
| C12 transactions | N/A | Read-only path; no transaction behaviour changed and no write was introduced. |
| C13 boundaries | PASS | The controller injects inbound service contracts; the view receives a ResponseDTO; no persistence exception is handled in delivery; no `$wpdb` outside Infrastructure. |
| C14 file existence | PASS | All three targets existed and were named in SCOPE FILES; the brief and report paths were checked absent beforehand. |
| C15 rule-to-skill sync | N/A | This step edited no AGENTS.md, rule file or `SKILL.md`. |
| C16 repairs | N/A | No A6b repair round ran. |
| Tracking guard | PASS | `git check-ignore -v .claude/rules .claude/skills docs/ai-reports` printed nothing for them; exit 1. |
| Patch whitespace | PASS | `git diff --check` produced no whitespace errors (one CRLF advisory on an edited file, which is the repository's existing line-ending state, not an introduced defect). |

## Acceptance criteria

| # | Criterion | Result | Evidence |
| --- | --- | --- | --- |
| 1 | A GET route to `CompanyController::show` with auth and capability middleware | PASS (path adapted) | `routes/web.php:82-89` registers `Route::get('company-show', ...)` named `companies.show`, inside the group at `routes/web.php:23-30` carrying `auth` and `capability:manage_options`. The brief's literal `companies/{id}` was not usable — see D-2026-11-15-24. |
| 2 | `show()` typed, calls `getCompanyDetails()`, guards `isFailure()` | PASS | `CompanyController.php:166-181`. The signature is `show(Request $request): ResponseInterface` rather than `show(int $id)`, because the id arrives as a query parameter like every other handler in this module — see D-2026-11-15-25. |
| 3 | The controller passes the `CompanyDetailResponseDTO` to the view | PASS | `CompanyController.php:180` passes `$result->getData()`, which is the DTO built by `CompanyResponseMapper::toDetailResponseDTO()`. |
| 4 | The view uses object property access | PASS | `grep -n '\$company\[' views/company/show.php` returns nothing. Every read is `$company->name`, `$company->website`, `$company->contacts`, `$company->deals` and so on. |

## Commands and checks

- Startup tracking guard: `git check-ignore -v .claude/rules .claude/skills docs/ai-reports` — no output; exit 1.
- Brief existence check before execution: `ls -la docs/ai-plans/company/03-wire-company-details.md` — present, 1170 bytes.
- `php -l app/Http/Controllers/Admin/CompanyController.php` — PASS.
- `php -l routes/web.php` — PASS.
- `php -l views/company/show.php` — PASS.
- T8 scan over `core/Company` — `$wpdb` confined to the two Infrastructure adapters; zero matches in Domain/Application.
- Acceptance check: `grep -n '\$company\[' views/company/show.php` — no output.
- `git diff --check` — PASS.
- `git restore docs/ai-plans/` — restored ten tracked files deleted by an external process (see Deviation 2).
- Tests paused per AGENTS.md; none run.

## Decisions

- **D-2026-11-15-24** — the route path is `company-show` with a query-parameter id, not the brief's `companies/{id}`, because an admin slug cannot carry a path segment. GAP/RULE CONFLICT.
- **D-2026-11-15-25** — `show()` takes `Request $request` and reads the id via `submittedId()`, rather than the brief's `show(int $id)`, for the same addressing reason and for consistency with `edit()`/`destroy()`. GAP.
- **D-2026-11-15-26** — ten tracked `docs/ai-plans/` files vanished mid-step through an external environment issue; restored with `git restore` under explicit developer authorization. RULE ERROR (environment).

All three are recorded in `docs/ai-reports/DECISIONS.md` with their basis, impact and proposed AGENTS.md change (none).

## Deviations and notes

1. **The route path and controller signature differ from the brief's literal text.** The brief specified `companies/{id}` and `show(int $id)`. Neither can address a wp-admin screen: WordPress resolves an admin screen by matching `?page=` against a slug, and a slug has no segments. Following the text literally would have produced a route that registers but can never be reached — the exact class of failure the audit's F-001 and G-001 described. The brief's *intent* (wire the details action) is met; its mechanics follow the module's established convention. Recorded as D-2026-11-15-24 and D-2026-11-15-25.

2. **An external environment issue deleted ten tracked plan files during this step.** Detected during the C2 scope audit: `git status --porcelain` showed ten ` D` entries under `docs/ai-plans/` for files this session listed present at its start and which no command of mine removes. Raised as a Structured HALT (H3/H9); the developer confirmed the cause was external and explicitly authorized `git restore docs/ai-plans/*.md`. All ten were restored, `git status` now shows zero deletions, and no code file was affected. Recorded as D-2026-11-15-26.

3. **The status is `DONE_WITH_NOTES`, not `DONE`.** The code work is complete and every acceptance criterion is verified against the source. The two notes above are the reason the step is not reported as a clean DONE: a deviation from the brief's literal mechanics, and an environment incident that required recovery.

4. **`views/company/index.php` was not edited.** The list's company name still links to the list URL rather than to the new detail screen, so the screen is reachable by its URL but not yet by a click from the list. Linking it is audit finding F-002's neighbourhood and was explicitly excluded by the brief's OUT OF SCOPE.

## Deferred and open items

- **F-002** (edit/detail URL construction in the list view) remains open, as the brief scopes it out.
- **G-002** (atomic bulk delete) remains open, as the brief scopes it out.
- **G-003's sibling:** the list view's name link could point at `companies.show` in a follow-up step, making the new screen discoverable by click.
- The pre-existing T8 purity findings outside the Company module (recorded in `11-company-read-helper-refactor.md`) are unchanged.

## Tests

Paused per AGENTS.md.

## Next step

Stop at the end of `Company/03`. No later step was started. The next candidate step would address the remaining open audit findings F-002 and G-002, or add the list-to-detail link noted above.
