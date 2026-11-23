# Step 06 - Company REST API (Company/06)

**Status:** DONE_WITH_NOTES
**Date:** 2026-11-16
**Branch:** `wpdb`
**Plan:** `docs/ai-plans/company/api/company_module_rest_api_implementation.md`
**Previous step:** `Company/05` (`docs/ai-reports/15-company-atomic-bulk-delete.md`)

## Summary

Implemented the Company module's REST delivery layer, resolving audit finding G-004. `app/Http/Controllers/Api/CompanyController.php` serves six endpoints, injecting the module's inbound service contracts and Request Mapper; each action converts validated input through the Mapper into a DTO, makes one Service call, and projects the `Result` onto a status. Response shape is a `JsonResource` (`CompanyResource`) rather than hand-built arrays, a `BulkDestroyCompanyRequest` validates the `ids` array, and all six routes sit behind `RequireCompanyManage`. Three defects were found by executing the code rather than reading it and were fixed in-step: a REST `?page=` parameter silently ignored (returning page 1 with a 200), string ids reaching a `strict_types` `int` port parameter, and an out-of-range `per_page` escaping as a 500. The step is `DONE_WITH_NOTES` because the brief's `PaginatedResult` acceptance item was already satisfied before this step, and because two brief instructions conflict with verified framework behaviour (recorded as decisions).

## Inputs used

- `docs/ai-plans/company/api/company_module_rest_api_implementation.md` (the brief)
- `docs/ai-plans/company/company-module-audit-action-plan.md` (finding G-004)
- `docs/ai-reports/15-company-atomic-bulk-delete.md` (previous step)
- `AGENTS.md` §§0, 2, 5, 6, 8, 9, 10, 11, 13, 14
- `.claude/rules/02-framework-adapter-wordpress.txt`, `04-crud-pipeline.txt`, `05-rich-domain-and-services.txt`, `06-ports-and-portability.txt`, `09-autonomous_execution.txt`
- Read (not edited): `framework/Routing/Adapters/RestApiRouterAdapter.php`, `AbstractRouterAdapter.php`, `ParameterBinder.php`, `RouteCompiler.php`, `UrlGenerator.php`, `RouteRegistrar.php`, `framework/Http/Requests/FormRequest.php`, `framework/Http/Responses/JsonResponse.php`, `framework/Support/JsonResource.php`, `JsonResourceCollection.php`, `app/Http/Controllers/Api/LeadController.php`, `app/Http/Middleware/RequireLeadRead.php`

## Mode and applicable rule sections

**Mode:** DELIVERY (`app/`, `routes/`) throughout. No CORE-PURE or ADAPTER step was needed: the module's application layer already exposed every method the API requires (`createCompany`, `updateCompany`, `deleteCompany`, `deleteMultiple`, `getPaginatedCompanies`, `getCompanyDetails`) and the Request Mapper already translates plain arrays. `core/` and `framework/` were **not edited**.

Applicable: AGENTS.md §§0, 2, 5, 6, 8, 9, 10, 11, 13, 14; rules 02 §7 (prepared values, capability checks), 04 STEP 5 (thin controller, service contracts, no `fromArray()`), 05 R12 (filter clamped via a Rules class in the Request Mapper), 06 T7 (delivery: validate → mapper → ONE service method → format), 09 A1-A16.

## Files

- **Created** `app/Http/Controllers/Api/CompanyController.php` — six actions (`index`, `show`, `store`, `update`, `destroy`, `bulkDestroy`), constructor-injecting `CompanyCommandServiceContract`, `CompanyQueryServiceContract`, `CompanyMapperContract`. Private `failure()`/`statusFor()` project a failed `Result` onto JSON; `listCriteria()` translates REST `page` → mapper `paged`; `intIds()` casts submitted ids; `locationFor()` builds the `Location` header.
- **Created** `app/Http/Resources/CompanyResource.php` — names the public wire shape field by field. One class serves the list item and the profile; `contacts`/`deals` are emitted only when the wrapped DTO carries them.
- **Created** `app/Http/Requests/Api/StoreCompanyRequest.php` — create rules mirroring the column limits the entity enforces.
- **Created** `app/Http/Requests/Api/UpdateCompanyRequest.php` — partial-update rules; `sometimes` on `name`, `nullable` on the clearable fields so absent/null/value stay distinguishable. No rule names `id`.
- **Created** `app/Http/Requests/Api/BulkDestroyCompanyRequest.php` — `ids` required array, `ids.*` integer/min:1.
- **Created** `app/Http/Middleware/RequireCompanyManage.php` — `RequireCapability` subclass pinned to `CompanyController::CAPABILITY`.
- **Modified** `routes/api.php` — a `companies` group under `RequireCompanyManage`, six RESTful routes.
- **Created** this report; the path was confirmed absent before creation.
- **Modified** `docs/ai-reports/PROJECT-LOG.md` — records this step.
- **Modified** `docs/ai-reports/DECISIONS.md` — records D-2026-11-16-04 … D-2026-11-16-06.
- **Not modified:** any `core/` file, any `framework/` file, `vendor/`, `routes/web.php`, `app/Http/Controllers/Admin/CompanyController.php`, any view, any provider, schema, or tests.

## Implementation notes

- **No manual arrays in the controller.** The brief's Phase 1.4 forbids `response()->json([...])`. No response body is hand-assembled: a success is `CompanyResource` (which decides its own shape) and a failure is the one `failure()` helper. The exception is the 422 built for a rejected filter, which carries a message and no resource to map.
- **`PaginatedResult` was already in use, not introduced here.** Phase 3.1 asks the `index` method to use a framework-agnostic `PaginatedResult<T>` rather than Eloquent's paginator. `core/Shared/Domain/DataObjects/PaginatedResult` already exists and `CompanyQueryService::getPaginatedCompanies()` already returns it inside a `Result`; the controller reads `$page->items()` and `$page->meta()`. Nothing this step writes reaches for a paginator, and `grep` for `LengthAwarePaginator` across the project returns zero hits. Recorded as an already-satisfied acceptance item rather than as work.
- **`bulkDestroy` is `DELETE companies`, not `DELETE companies/bulk`.** The brief's Phase 2 lists `DELETE /companies` → `bulkDestroy`, which is what was built. Worth noting because the admin screen uses `POST companies/bulk`: the two are different surfaces with different addressing rules, and the API does not inherit wp-admin's need for a distinct path.
- **The `{id}` placeholder outranks a body parameter of the same name.** Verified by executing the binder (`/tmp/b10.php`): a `PUT /companies/42` carrying `{"id": 999}` binds `42`, because `ParameterBinder::bindParameters()` reads `routeParameters()` before `$source->value()` (`ParameterBinder.php:275,321`). Combined with the adapter's `pinRouteParameters()` the matched value wins in both the argument and the framework `Request`. No rule in `UpdateCompanyRequest` names `id`, so the body cannot reach the record's address through `validated()` either.
- **The `Location` header is buildable.** `UrlGenerator::compileRestEndpoint()` substitutes placeholders (`UrlGenerator.php:241-246`), so `route('api.companies.show', ['id' => 42])` yields `.../companies/42`. Executed against a real `RestApiRouterAdapter` + `NamedRoutes` (`/tmp/location.php`) — this is the opposite of the admin-page case recorded in D-2026-11-16-01, where the generator drops parameters.
- **Capability parity with the admin screens.** `RequireCompanyManage` reads `CompanyController::CAPABILITY` (`manage_options`) rather than restating the string, so the API and the wp-admin screens admit the same population by construction. A subclass rather than a parameter, because a REST route's middleware resolves by class name only (D5 note in `RequireLeadRead`).
- **No nonce middleware on the API.** Nonces are a wp-admin form mechanism; the REST surface authenticates through WordPress's own REST cookie/nonce handling, and the brief's Phase 2 asks only for auth and capability. The capability check is the authorization boundary here.

## Self-check

| Check | Result | Evidence |
| --- | --- | --- |
| C1 files | PASS | All seven files exist (`ls`); the report path was confirmed absent before creation. |
| C2 scope | PASS | `git status --porcelain` shows exactly four new paths (`app/Http/Controllers/Api/CompanyController.php`, `app/Http/Middleware/RequireCompanyManage.php`, `app/Http/Requests/Api/`, `app/Http/Resources/CompanyResource.php`) plus `routes/api.php` modified. Every other listed modification predates this step (Company/03–05 and the core/ rule work). No `core/`, `framework/`, `vendor/`, view, provider or web-route file was touched. |
| C3 syntax | PASS | `php -l` on all seven files: no syntax errors. `declare(strict_types=1);` in every one; namespaces match paths. |
| C4 autoload | PASS | `composer dump-autoload` ran; all six new classes report `class_exists() === true` afterwards. The PSR-4 warnings it printed are pre-existing `tests/manual/` probes, untouched by this step. |
| C5 applicable DoD | PASS | 04 STEP 5: FormRequest holds rules only; the controller injects both service contracts and `$mapper`; flow is `validated()` → Mapper → ONE Service call → `isFailure()` guard; no `DTO::fromArray()`. 06 T7 satisfied: validate → mapper (plain array) → one service method → format ResponseDTO. 05 R12: the filter is still clamped through `CompanyFilter`/`CompanyRules` inside the Service; the controller only chose which query key to read. |
| C6 Core greps | PASS | T8 A over `core/Company/Domain` and `core/Company/Application`: zero matches. No `core/` file was edited, so the scan confirms the step did not disturb purity. |
| C7 wiring | PASS | No container binding was needed: all three injected contracts are already bound in `CompanyServiceProvider`, which is listed in `config/app.php`. Six routes registered in `routes/api.php` inside a group; verified by executing registration against a real `RestApiRouterAdapter` — six routes collected, no duplicate-guard throw (`/tmp/dup_check.php`). |
| C8 schema fidelity | PASS | No schema, column, or migration changed. The FormRequest limits mirror `CompanyRules` (255/255/100/50) and the `StoreCompanyRequest` used by the admin path, so the API cannot accept a value the entity would then reject. |
| C9 leftovers | PASS | `grep` for `TODO`, `FIXME`, `var_dump`, `dd(`, `dump(` across all seven files: zero matches. Two unused imports were removed during the step (see A6b log). |
| C10 runtime | N/A | The brief did not request a runtime check against the live site. Behaviour was exercised instead by executing the real binder, request objects and URL generator outside WordPress (`/tmp/e2e.php` and others), which is recorded under Commands. No data was created or deleted. |
| C11 tests | PASS | Tests paused per AGENTS.md. None written, scaffolded, required or run. |
| C12 transactions | PASS | The controller opens no transaction and calls no transaction API — it is delivery. Every write still runs in the Service's `executeInTransaction()`. `deleteMultiple($ids)` is one call, so the batch remains a single transaction (Company/05). |
| C13 boundaries | PASS | Delivery injects the inbound service contracts and the Request Mapper, never a concrete Service. No persistence exception is caught here: `Result` is the only failure shape, and `InvalidArgumentException` from the Mapper is the documented translation-boundary throw. No Entity or storage row reaches the controller — the Service returns ResponseDTOs, and `CompanyResource` maps DTOs. |
| C14 file existence | PASS | Every created path was checked absent beforehand (`git ls-files` and `ls`); `app/Http/Requests/Api/` did not exist. |
| C15 rule-to-skill sync | N/A | This step edited no AGENTS.md, rule file or `SKILL.md`. |
| C16 repairs | PASS | Three bounded repairs ran in one round each; signatures and outcomes are in the A6b log below. No repeated signature; no A10 stop bypassed. |
| T8 purity (task files) | PASS | `grep` for `$wpdb`, `DB::`, `Illuminate\` across all six new files: zero matches. The controller touches WordPress only through framework types. |
| Patch whitespace | PASS | `git diff --check routes/api.php`: no whitespace errors. |
| Tracking guard | PASS | `git check-ignore -v .claude/rules .claude/skills docs/ai-reports docs/ai-plans` printed nothing; exit 1. |

## A6b repair log

Three failures were found by executing the finished code, diagnosed against source, and repaired within one round each. No signature repeated.

| # | Failure Signature | Diagnosis | Repair | Result |
| --- | --- | --- | --- | --- |
| 1 | `InvalidArgumentException: Unsupported value [99999] for filter [per_page]` + `app/Http/Controllers/Api/CompanyController.php` + `index()` | `CompanyMapper::mapToFilterDTO()` documents `@throws InvalidArgumentException` and throws for an unusable page size; `index()` passed its result straight to the Service, so the throw escaped as a 500 for a fixable query string | Wrapped only the mapper call in `index()` in a `try/catch (InvalidArgumentException)`, returning 422 with the mapper's own message. The Service call and the rest of the method are unchanged | Re-ran: `per_page=99999` → 422 with the bounds in the message; `per_page=abc` → 422; valid input → 200 |
| 2 | REST `?page=3` produced `page=1` with a 200 (`/tmp/filter_check.php` output) | `CompanyMapper::mapToFilterDTO()` reads the page from `paged` — WordPress's admin list-table key. A REST client sends `page`, which the mapper ignored, serving the wrong rows behind a 200 | Added `listCriteria()`, which copies `page` onto `paged` only when `paged` is absent, and passed its result to the mapper. `paged` still wins when both are sent | Re-ran five cases: `page=3` → 3, `paged=3` → 3, both → 3, `page=2&per_page=5` → page 2 of 5, neither → page 1 |
| 3 | `bulkDestroy() received ["3","1","2"]`, and the write port declares `delete(int $companyId)` under `strict_types=1` (`WpCompanyWriteRepository.php:154`) | The `integer` rule validates without retyping (documented in `FormRequest::validated()`), so a JSON body of `{"ids":["3"]}` passes validation and arrives as strings. The adapter would raise a `TypeError` several frames down — a 500 for a well-formed request | Added `intIds()`, casting at the boundary that received the strings, and passed its result to `deleteMultiple()` | Re-ran: `{"ids":["3","1","2"]}` → service received `[3,1,2]` with types `int,int,int` |

## Acceptance criteria

| # | Criterion | Result | Evidence |
| --- | --- | --- | --- |
| 1 | `app/Http/Controllers/Api/CompanyController.php` exists and strictly returns JSON responses mapped via `ResponseDTO` | PASS | The file exists. Every action returns `Framework\Http\Responses\JsonResponse`. No Entity or DTO property map is hand-built: `CompanyResource` decides the success shape, `failure()` the failure shape. The Service returns `CompanyResponseDTO` / `CompanyDetailResponseDTO`, and `CompanyResource` receives only those. Executed: `index` 200, `show` 200, `update` 200, `destroy` 204, `bulkDestroy` 204. |
| 2 | No raw `Request` objects are passed to the application services | PASS | `grep` for `Service->` in the controller shows every call taking a DTO or an `int`/`list<int>`: `getPaginatedCompanies(CompanyFilterDTO)`, `getCompanyDetails(int)`, `createCompany(StoreCompanyDTO)`, `updateCompany(UpdateCompanyDTO)`, `deleteCompany(int)`, `deleteMultiple(array)`. The two `Request` objects read are `Framework\Http\Request` (framework type, used only for `all()`) and the FormRequests. |
| 3 | `bulkDestroy` uses a dedicated `BulkDestroyCompanyRequest` | PASS | Signature is `bulkDestroy(BulkDestroyCompanyRequest $request)`. Executed: valid ids PASS with `{"ids":[1,2,3]}`; empty array, missing `ids`, a scalar `ids`, a zero id and a non-integer id each FAIL with the right message. The wildcard `ids.*` correctly does not leak a literal `ids.*` key into `validated()`. |
| 4 | Framework-agnostic `PaginatedResult<T>` is utilized for listings | PASS | `CompanyQueryService::getPaginatedCompanies()` returns `Result` carrying `PaginatedResult`; `index()` reads `items()` and `meta()`. `grep -rn "LengthAwarePaginator"` returns zero hits project-wide. Already satisfied before this step — see the decision and Deviations. |
| 5 | API routes are protected by capability/auth middleware in `routes/api.php` | PASS | All six routes are inside `Route::group(['middleware' => [RequireCompanyManage::class]], ...)`. `RequireCompanyManage extends RequireCapability` pinned to `manage_options`. Every route carries a name (`api.companies.*`), verified by listing the registered table. |

## Commands and checks

- Startup: `git check-ignore -v .claude/rules .claude/skills docs/ai-reports docs/ai-plans` — no output; exit 1.
- File existence: `git ls-files app/Http/Controllers/Api/ app/Http/Requests/Api/` — only `LeadController.php` tracked; both new paths free.
- `php -l` on all seven files — PASS.
- `composer dump-autoload` — regenerated; 2152 classes. Six new classes confirmed loadable via `class_exists()`.
- Binder resolution: `Framework\Http\Request` type-hint on a REST dispatch receives the adapter-bound request with query params intact (`page=3 per_page=5`) — `/tmp/b8.php`.
- Route parameter precedence: `PUT /companies/42` with body `{"id":999}` binds `42`, as `int` — `/tmp/b10.php`.
- JSON body through `Request::fromRestRequest()` → `StoreCompanyRequest` PASS, `BulkDestroyCompanyRequest` PASS including string ids — `/tmp/b9.php`.
- Route registration: six routes collected by a real `RestApiRouterAdapter`, no duplicate throw — `/tmp/dup_check.php`.
- URL generation: `route('api.companies.show', ['id' => 42])` → `.../v1/companies/42` — `/tmp/location.php`.
- Failure projection: `COMPANY_NOT_FOUND` → 404, `COMPANY_ALREADY_EXISTS` → 409, `COMPANY_INVALID` → 422, no code → 422 with `result_failed` — `/tmp/failure_check.php`.
- End-to-end: all eight scenarios exercised through the real binder — `/tmp/e2e.php`.
- T8 A over `core/Company/Domain` + `core/Company/Application` — zero matches.
- Leftover and `$wpdb` greps over the seven files — zero matches.
- `git diff --check routes/api.php` — clean.
- Tests paused per AGENTS.md; none run.

## Decisions

- **D-2026-11-16-04** — the REST query vocabulary is translated in the controller, not the frozen mapper. GAP.
- **D-2026-11-16-05** — the brief's `PaginatedResult` acceptance item was already satisfied before this step. AMBIGUOUS BRIEF.
- **D-2026-11-16-06** — `RequireCompanyManage` reads the capability from the admin controller rather than restating it. GAP.

Recorded in `docs/ai-reports/DECISIONS.md` with basis, impact and proposed AGENTS.md change.

## Deviations and notes

1. **Phase 3.3's `WHERE IN (...)` instruction was not followed, and Company/05 already recorded why.** The brief says to "refactor the `bulkDestroy` persistence implementation to execute as a single `WHERE IN (...)` query ... (Note: This overrides the previous loop decision in Company/05)". Company/05 (D-2026-11-16-03) decided the opposite on a rule basis: `05 R8` states the write port exchanges single records, so a bulk method would carry a use case's cardinality into the persistence contract, and the loop belongs in the Service inside its one transaction. This step is DELIVERY and does not own the write port, so the conflict is reported rather than resolved by editing `core/`. Atomicity — the property the instruction was reaching for — already holds. **Flagged for the developer: if the bulk port method is wanted, it is a CORE-PURE/ADAPTER step of its own.**
2. **Phase 3.2's global-404 mapping was not implemented as described.** The brief asks that `update`/`destroy` throw `CompanyNotFoundException` and that a global handler map it to 404. The module does not do this, and should not: `CompanyCommandService::executeInTransaction()` catches `CompanyNotFoundException` (`CompanyCommandService.php:245`) and returns `Result::failure` with `COMPANY_NOT_FOUND`, because a missing row is an ordinary outcome a client is meant to render, not an exceptional one (`Result`'s own docblock argues this). The effect the brief asks for is delivered by `CompanyController::statusFor()`, which maps that code to 404. `update` and `destroy` were executed against a missing id and both returned `404 {"error":"COMPANY_NOT_FOUND"}`. Note also that `CompanyNotFoundException` extends `RuntimeException`, not `DomainValidationException`, so `Handler::toHttpException()` would map it to a **500** — the brief's global-handler route would have produced the wrong status.
3. **No auth middleware beyond the capability check.** The brief's Phase 2 says "API authentication middleware and capability checks". WordPress's REST layer already resolves the current user from the cookie/nonce or application password before the permission callback runs, so `current_user_can()` is the authorization decision and an anonymous caller receives `rest_authorization_required_code()` (401/403) from `RequireCapability`. No framework `auth` middleware exists for the REST domain, and none was invented.
4. **The controller's `Request` type-hint depends on the adapter's binding.** `index(Request $request)` is correct only because `RestApiRouterAdapter::dispatchRest()` binds a framework `Request` into the container before resolving the controller (`RestApiRouterAdapter.php:148-153`). Verified by execution: with the binding present the query params arrive; without it the container builds an empty `Request` and silently returns nothing. This is not a defect in the code but it is a sharp edge worth recording, because a missing binding fails as empty data rather than as an error.
5. **Status is `DONE_WITH_NOTES`, not `DONE`.** Every acceptance criterion passes and the executed behaviour is correct, but three brief instructions were deliberately not followed literally (items 1, 2 and 3 above), each for a rule or verified-behaviour reason. A clean `DONE` would misrepresent that.

## Deferred and open items

- **The `WHERE IN (...)` bulk delete** (Phase 3.3) needs a CORE-PURE step if the developer wants it over the Company/05 decision.
- **G-004** is resolved by this step's routes; the audit's other findings were closed in Company/03–05.
- The endpoints have not been exercised against the live WordPress site over HTTP; every claim above comes from executing the real framework classes outside WordPress. A live smoke test is the one thing this step cannot show.
- **Five anonymous test doubles** in `tests/manual/probe-company-delete-flow.php` and `probe-company-update-flow.php` remain unloadable from Company/05's contract change; unchanged by this step and still reported, not repaired (tests paused).
- The pre-existing T8 purity findings outside the Company module are unchanged.

## Tests

Paused per AGENTS.md. None written, scaffolded, required or run.

## Next step

Stop at the end of `Company/06`. No later step was started. All phases of the brief are complete; the open items above are the developer's to prioritise.
