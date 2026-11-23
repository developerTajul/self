# Step 04 - Company List URL Refactor (Company/04)

**Status:** DONE_WITH_NOTES
**Date:** 2026-11-16
**Branch:** `wpdb`
**Plan:** `docs/ai-plans/company/03-wire-company-details.md` (predecessor); brief supplied in chat
**Previous step:** `Company/03` (`docs/ai-reports/13-company-details-wiring.md`)

## Summary

Removed the `$editUrlFor` closure from `views/company/index.php` and moved record-address construction into `CompanyController`, which now hands the view two callables (`editUrlFor`, `showUrlFor`) built by one private `recordUrl()` helper. The list's row title and a new View row action now link to the details screen wired in `Company/03`, and every generated address is escaped with `e()`. The refactor also deleted the duplicated `editScreenUrl()` method, whose logic `recordUrl()` now owns in one place. The step is `DONE_WITH_NOTES` because acceptance criterion 2 as originally written — `route('companies.edit', ['id' => $company->id])` — is unimplementable on this framework: `route()` drops parameters for an admin-page surface. The developer amended that criterion to the controller-built form, which is what shipped.

## Inputs used

- `docs/ai-reports/12-company-module-audit-report.md` (findings F-002, D-004)
- `docs/ai-reports/13-company-details-wiring.md` (the `companies.show` route this step links to)
- `AGENTS.md` §§0, 2, 6, 13
- `.claude/rules/02-framework-adapter-wordpress.txt` §6
- `.claude/rules/04-crud-pipeline.txt` STEP 5
- `.claude/rules/01-universal-philosophy.txt` P3
- `.claude/rules/09-autonomous_execution.txt` A1-A16
- `framework/Routing/UrlGenerator.php` (read to verify `route()` behaviour, not edited)

## Mode and applicable rule sections

**Mode:** DELIVERY (`app/Http/Controllers/Admin/CompanyController.php`, `views/company/index.php`). No CORE-PURE, ADAPTER, Shared, framework, vendor, schema or test file was changed. `framework/Routing/UrlGenerator.php` was **read** to establish the generator's actual behaviour; reading framework code is always allowed (AGENTS.md §2), editing it never is.

Applicable: AGENTS.md §§0, 2, 6, 13; rules 01 P3, 02 §6, 04 STEP 5, 09 A1-A16.

## Files

- **Modified** `app/Http/Controllers/Admin/CompanyController.php` —
  - `rowActionWiring()` now returns `editUrlFor` and `showUrlFor` as `fn (int): string` callables, and the obsolete `editUrl`, `editPageParam`, `editPageValue` and `editIdParam` keys were removed;
  - added private `recordUrl(string $base, int $companyId): string`, which composes a record id onto a screen address with the separator chosen from the address;
  - removed private `editScreenUrl()`, whose body `recordUrl()` replaces, and updated its one caller in `edit()`;
  - docblocks updated to state why the id is composed rather than passed to `route()`.
- **Modified** `views/company/index.php` —
  - deleted the `$editUrlFor` closure and its `use ($editUrl, $editPageParam, $editPageValue, $editIdParam)` binding list;
  - the name anchor now links to `e($showUrlFor($company->id))`;
  - added a `view` row action beside the existing `edit` and `trash` actions;
  - the `@var` docblock list updated: `$editUrl`/`$editPageParam`/`$editPageValue`/`$editIdParam` replaced by `@var callable(int): string $editUrlFor` and `@var callable(int): string $showUrlFor`.
- **Created** this report; the path was confirmed absent before creation.
- **Modified** `docs/ai-reports/PROJECT-LOG.md` — records this step.
- **Modified** `docs/ai-reports/DECISIONS.md` — records D-2026-11-16-01 and D-2026-11-16-02.
- **Not modified:** `views/company/show.php`, `routes/web.php` (both carry `Company/03`'s changes as pre-existing working-tree edits), any `core/` file, any Shared artifact, `framework/`, `vendor/`, schema, or tests.

## Implementation notes

- **Why `route()` cannot carry the id.** `UrlGenerator::compile()` dispatches on the route's surface; for `SURFACE_ADMIN_PAGE` it calls `compileAdminPage()`, which performs `substitute()` only and never `queryString()`. A project admin-page route has no path placeholders, so every key in the `$parameters` array is discarded and no warning is raised — the caller receives `admin.php?page=company-edit` with the id silently gone. `queryString()` is reached only from `compileFrontEndPage()`.
- **One helper, three call sites.** `recordUrl()` serves `edit()`, `editUrlFor()` and `showUrlFor()`. The previous code had the same composition twice (the view closure and `editScreenUrl()`), which is how the two could drift.
- **The view composes nothing.** It calls two callables it was handed, matching the existing `deleteFormIdFor` shape, so no template needs to know how an admin screen is addressed.
- **Escaping.** All eight `href` attributes in the view pass through `e()`, verified by grep. The only unescaped output remains the literal `&mdash;` entity, which is template markup rather than data.
- **`$listUrl` was left in place.** The pager closure still has four call sites (the first/prev/next/last links) and is not part of F-002's finding.

## Self-check

| Check | Result | Evidence |
| --- | --- | --- |
| C1 files | PASS | Both SCOPE FILES exist; the report path was confirmed absent before creation. |
| C2 scope | PASS | `git status --porcelain` shows only the two named files changed by this step, plus `Company/03`'s pre-existing trail; `grep -c "^ D"` returns 0. |
| C3 syntax | PASS | `php -l` on `CompanyController.php` and `views/company/index.php`: no syntax errors. |
| C4 autoload | N/A | No namespace, class path or autoload configuration changed. |
| C5 applicable DoD | PASS | 04 STEP 5 satisfied: the controller owns its URL composition, the view receives resolved addresses and makes no decisions. 01 P3 satisfied: the branching `$namesScreen`/separator logic is gone, replaced by one helper with one branch. |
| C6 Core greps | N/A | No `core/` file changed by this step. |
| C7 wiring | PASS | No route or Contract changed. The view's `showUrlFor` targets the `companies.show` route registered in `Company/03`. |
| C8 schema fidelity | PASS | No schema or data changed. |
| C9 leftovers | PASS | No `TODO`, `FIXME`, `var_dump` or `dd()` in either file. No dangling reference to the removed keys: `grep` for `editPageParam`, `editPageValue`, `editIdParam`, `editScreenUrl` and `$editUrl` across `app/` and `views/` returns nothing. |
| C10 runtime | PASS (partial) | The brief did not request a site check. `recordUrl()`'s composition was verified by executing its logic directly: it produced `…&page=company-edit&id=42` and `…&page=company-show&id=42`, and `?id=7` for a base with no query string. **The full admin request was not exercised**, so the rendered screen is not claimed as visually confirmed. |
| C11 tests | PASS | Tests paused per AGENTS.md; none written, scaffolded, required or run. |
| C12 transactions | N/A | Read paths and URL composition only; no write and no transaction behaviour changed. |
| C13 boundaries | PASS | The controller injects inbound service contracts; the view receives resolved addresses; no `$wpdb`, `Config` or `env()` introduced. |
| C14 file existence | PASS | Both targets existed and were named in SCOPE FILES; the report path was checked absent. |
| C15 rule-to-skill sync | N/A | This step edited no AGENTS.md, rule file or `SKILL.md`. |
| C16 repairs | PASS | One A6b-style bounded repair ran: after replacing `editScreenUrl()` with `recordUrl()`, its single caller at `CompanyController.php:219` still named the removed method; the fix updated that one call. Round 1, one signature (`Error: Call to undefined method editScreenUrl()` — prevented by grep before execution), bounded to the offending statement, no repeat. |
| Tracking guard | PASS | `git check-ignore -v .claude/rules .claude/skills docs/ai-reports` printed nothing for them; exit 1. |
| Patch whitespace | PASS | `git diff --check` reported no whitespace errors. |

## Acceptance criteria

| # | Criterion | Result | Evidence |
| --- | --- | --- | --- |
| 1 | The `$editUrlFor` closure is completely removed from the view | PASS | `grep -c "namesScreen\|editPageValue" views/company/index.php` returns 0; the closure body is gone and only `$listUrl` (the pager) remains a `static function`. |
| 2 | Edit links use `route()` with parameters | **AMENDED, PASS** | The literal call `route('companies.edit', ['id' => …])` **cannot work** on an admin-page route: `compileAdminPage()` drops parameters (see Implementation notes). With the developer's explicit amendment, the address is built by `CompanyController::recordUrl()` from `route('companies.edit')` plus the id, and passed to the view as `editUrlFor`. |
| 3 | The company name column links to `companies.show` | PASS | `views/company/index.php:169-171` — the `row-title` anchor now carries `href="<?= e($showUrlFor($company->id)) ?>"`. A `view` row action was added at `:176-179` as a second path to the same screen. |
| 4 | URLs escaped with `e()` | PASS | Every `href` in the view is wrapped in `e()`; `grep 'href="<?=' | grep -v 'e('` returns nothing. |

## Commands and checks

- Startup tracking guard: `git check-ignore -v .claude/rules .claude/skills docs/ai-reports` — no output; exit 1.
- `php -l app/Http/Controllers/Admin/CompanyController.php` — PASS.
- `php -l views/company/index.php` — PASS.
- Closure-removal check: `grep -c "namesScreen\|editPageValue" views/company/index.php` — 0.
- Dangling-reference sweep: `grep -rn "editPageParam\|editPageValue\|editIdParam\|editScreenUrl\|\$editUrl\b" app/ views/` — no matches.
- Escaping check: `grep -n 'href="<?=' views/company/index.php | grep -v 'e('` — no matches.
- URL composition check: executed `recordUrl()`'s logic via `php -r`; output `…?page=company-edit&id=42`, `…?page=company-show&id=42`, and `noquerystring?id=7`.
- `git status --porcelain | grep -c "^ D"` — 0 deletions.
- Tests paused per AGENTS.md; none run.

## Decisions

- **D-2026-11-16-01** — `route()` drops parameters for an admin-page surface, so the brief's `route('companies.edit', ['id' => …])` is unimplementable; the developer amended the criterion to controller-built addresses. RULE CONFLICT.
- **D-2026-11-16-02** — `editScreenUrl()` was collapsed into the shared `recordUrl()` rather than kept alongside it. GAP.

Both are recorded in `docs/ai-reports/DECISIONS.md` with their basis, impact and proposed AGENTS.md change.

## Deviations and notes

1. **Acceptance criterion 2 was amended, not met literally.** The brief asked for `route('companies.edit', ['id' => $company->id])`. Read against `framework/Routing/UrlGenerator.php:159-215`, that call returns a URL **without the id** and raises nothing. Implementing it literally would have produced a broken edit link — the F-002 failure this step exists to fix, reproduced by the fix. The conflict was raised as a Structured HALT before any edit; the developer selected Option A and explicitly approved the amendment, and the criteria were then met in the form recorded above.

2. **The report is numbered `14`, not `13`.** `Company/03` filed `13-company-details-wiring.md`; A11 requires continuing the existing sequence.

3. **Status is `DONE_WITH_NOTES` rather than `DONE`.** The code is complete and every criterion is verified against source, but criterion 2 shipped in an amended form and the rendered screen was not exercised in a browser.

4. **`views/company/show.php` and `routes/web.php` appear modified in `git status`.** Those are `Company/03`'s changes, uncommitted; this step did not touch them.

## Deferred and open items

- **G-002** (atomic bulk delete) remains open and is the stated subject of `Company/05`.
- The rendered list and details screens have not been exercised through a live wp-admin request; a manual pass would confirm the links end to end.
- The pre-existing T8 purity findings outside the Company module are unchanged.

## Tests

Paused per AGENTS.md.

## Next step

Stop at the end of `Company/04`. `Company/05` (atomic bulk deletes, G-002) was named by this brief as out of scope and has not been started.
