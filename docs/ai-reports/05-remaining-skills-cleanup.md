# Step 05 — Remaining Skills Cleanup (`core/05-remaining-skills-cleanup`)

**Status:** DONE_WITH_NOTES
**Date:** 2026-11-15
**Branch:** `wpdb`
**Plan:** `docs/ai-plans/action_plan_remaining_skills_cleanup.md` (self-labelled `core/06` — see Deviations 1)
**Previous steps:** `01-repository-split-refactor.md`, `02-repository-rules-cleanup.md`, `03-skill-files-cleanup.md`, `04-agents-and-rules-upgrade.md`

## Summary

Rewrote the five remaining skills — `delete-action-builder`, `edit-form-builder`, `form-builder`,
`index-action`, `store-action-builder` — against the live WordPress `$wpdb` / CQRS code, removing
every Laravel/Eloquent instruction. The plan's two prescribed replacement APIs were both verified
**wrong for this project** before any edit: it said to replace `Route::resource()` with
`register_rest_route()` (used zero times in `app/`, `routes/`, `core/` — it is an internal detail of
`framework/Routing/Adapters/`), and to replace Eloquent with the `$wpdb` base-class helpers
(`findRecordForUpdate`, `write`, `insert`) which are `protected` on `BaseWriteRepository` and
cannot be called from a Service. Both were corrected with the developer's explicit agreement
(D-2026-11-15-12, D-2026-11-15-13).

Beyond the three defect classes the plan named, the files carried three more it did not: Blade
templates (`resources/views/**/*.blade.php`, `@csrf`, `@method`) where this project uses plain
`views/*.php`; an Eloquent `Collection` + `LengthAwarePaginator` pagination shape where the adapter
runs its own `$wpdb` query; and a delete port taking an Entity where the live port takes an `int`.
Each skill was rewritten against a named live reference, not search-and-replaced.

## Inputs used

`docs/ai-plans/action_plan_remaining_skills_cleanup.md`; `.claude/rules/09-autonomous_execution.txt`
(A5b, the sweep this step triggers); `AGENTS.md` §0/§6/§9/§10/§11 and §D4/§D6; the five skill files;
`docs/ai-reports/04-agents-and-rules-upgrade.md` (which itemised all 30 hits with line numbers).

Live code read as the reference for every rewritten claim — no claim was taken from the plan:

| Reference | Used to correct |
| --- | --- |
| `routes/web.php` (`companies/delete`, `companies/store`, `companies/update`, `EDIT_SLUG`, `SCREEN_SLUG`, `CREATE_SLUG`) | routing in all five skills |
| `app/Http/Controllers/Admin/CompanyController.php` (`destroy`, `edit`, `submittedId`, `formWiring`) | controller + view tasks |
| `core/Company/Application/Services/CompanyCommandService.php` (`deleteCompany`, `executeInTransaction`) | Service task, delete port shape |
| `core/Company/Infrastructure/Repositories/WpCompanyWriteRepository.php` (`create`, `delete`) | write-adapter task |
| `core/Company/Infrastructure/Repositories/WpCompanyReadRepository.php` (`getFilteredCompanies`) | `index-action` pagination |
| `core/Shared/Infrastructure/Repositories/{AbstractWpRepository,BaseWriteRepository,BaseReadRepository}.php` | base-class vocabulary and visibility |
| `views/company/{index,edit,create}.php` | view tasks (plain PHP, `e()`, `old()`, nonce fields) |
| `core/Company/Domain/Contracts/CompanyRepositoryContract.php` (`delete(int $companyId): void`) | port signature |

## Rules and skills applied (PROCESS GUARD, before editing)

- §0 STARTUP PROTOCOL — Context Hydration read `PROJECT-LOG.md` (4 rows) and the last 3 decisions
  (`D-2026-11-15-09/10/11`); Git Tracking Guard passed (`git check-ignore -q` → exit 1 for
  `.claude/rules`, `.claude/skills`, `docs/ai-reports`, i.e. tracked).
- §2 MODE DETECTION — documentation mode. No `core/`, `app/`, `framework/` or `vendor/` file edited.
- §9 CQRS PORT SPLIT / §D6 — the write/read adapter split is what the rewritten repository tasks
  now teach (`BaseWriteRepository` vs `BaseReadRepository`), replacing the Eloquent single-repo shape.
- §10 TRANSACTIONS — every Service snippet rewritten to the mandated shared
  `executeInTransaction()`; the inline `TransactionManagerContract::run()` + per-method catch blocks
  the old skills taught are gone.
- §11 PORTS AND ADAPTERS / §D4 — `Wp{Module}` prefix and the `$wpdb`-only adapter rule applied.
- §D5 — hardcoded English messages in Core snippets replaced with `TranslatorContract` calls.
- 09 A5b — the sweep this step added is run below (`STALE_SKILLS_DETECTED`).
- Tests: **Paused per AGENTS.md.** No test written, scaffolded or run.

## The plan's two prescribed fixes were wrong (verified before editing)

| Plan instruction | Verified reality | Correction applied |
| --- | --- | --- |
| Replace `Route::resource()` with `register_rest_route()` | `register_rest_route()` appears **0 times** in `app/`, `routes/`, `core/`; it is an internal call inside `framework/Routing/Adapters/RestApiRouterAdapter.php:119` and `framework/Routing/Router.php:238/266`. The live API is the `Route::` facade. Delete/store/update are **web** routes ending in redirects, not REST endpoints. | Teach `Route::post(...)` / `Route::get(...)` with `->name()`, `->withOptions()`, `->middleware(['nonce:' . ...])`, per `routes/web.php`. |
| Strip Eloquent; use `findRecordForUpdate` / `write` / `insert` | These are `protected` on `BaseWriteRepository` (`core/Shared/Infrastructure/Repositories/BaseWriteRepository.php:93/144/247`) — unreachable from a Service. The live port is `CompanyRepositoryContract::delete(int $companyId): void`. | Service side teaches the **port**; the adapter side teaches the `$wpdb` helper. Two layers, two vocabularies. |

Deviating from a plan's explicit instruction is a scope decision, not a judgement call I take alone:
both were raised with the developer, who chose "Route:: facade, per real code" and "Service calls the
port; adapter uses the helpers" (D-2026-11-15-12, D-2026-11-15-13).

## Defect classes found and fixed

The plan named three. The files carried six.

| # | Defect class | Where | Fix |
| --- | --- | --- | --- |
| 1 | `Route::resource()` — no such router in WordPress | all five, 3 hits each | hand-written `Route::get`/`Route::post` per real `routes/web.php` |
| 2 | `02-framework-adapter-laravel.txt` — file does not exist; `09-autonomous-execution.txt` — wrong separator | all five | corrected to `02-framework-adapter-wordpress.txt` and `09-autonomous_execution.txt` |
| 3 | Eloquent: `Model`, `$fillable`, `->save()`, `->delete()`, `getAttributes()`, `toArray()`, `createRecord()`, `paginateEloquent()`, `LengthAwarePaginator`, `Collection::map()->all()` | all five (repository/Service tasks) | `$wpdb` + `AbstractWpRepository`/`BaseWriteRepository`/`BaseReadRepository`, `array_map`, `PaginatedResult::fromSlice` |
| 4 | `DB::` facade, manual `beginTransaction()`/`commit()`/`rollback()`, inline `run()` with per-method catches | all five | the mandated shared `executeInTransaction()` (§10) |
| 5 | **Blade**: `resources/views/backend/pages/**/*.blade.php`, `@csrf`, `@method('DELETE'/'PUT')`, `{{ }}`, `{{-- --}}`, `old()` called "Laravel's" | `delete` (T2, T7), `edit` (T2, T7, frontmatter), `form` (T3, policy, checklist), `store` (T7, checklist) | plain `views/{module}/*.php`, `e()` escaping, the project's own `old()`/`field_error()` helpers, nonce fields from the controller |
| 6 | **Port/shape mismatches**: `delete(Entity): bool` (live: `delete(int): void`), `$result->message` as a property (live: `message()`), `$result->data` (live: `getData()`), flash key `success` (live: `notice`), edit via route id (live: query-string id + `hidden => true`) | `delete`, `edit` | matched to the live controller/port |

The Blade class (#5) is the largest and was not in the plan. It matters more than cosmetics: a skill
telling an agent to write `.blade.php` files produces files this project cannot render.

## STALE_SKILLS_DETECTED

A5b sweep, run because this step edited five `SKILL.md` files. Swept terms: `Route::resource`,
`02-framework-adapter-laravel`, `09-autonomous-execution.txt`, `getAttributes`, `paginateEloquent`,
`LengthAwarePaginator`, `createRecord(`, `->save()`, `lockForUpdate`, `resources/views`,
`.blade.php`, `@csrf`, `@method`, `register_rest_route`.

**Result: `STALE_SKILLS_DETECTED: none` — no remaining hit is an instruction.** Every surviving
occurrence is a deliberate prohibition. Verified mechanically, not by eye: a script matched each
`Route::resource` hit against a negation word on the same line.

| Skill | `Route::resource` hits | All negations? |
| --- | --- | --- |
| `delete-action-builder` | 1 | yes — "No `Route::resource()`, and no direct `register_rest_route()`" |
| `edit-form-builder` | 1 | yes |
| `form-builder` | 1 | yes |
| `index-action` | 1 | yes |
| `store-action-builder` | 1 | yes |
| `update-action-builder` (not in this step's scope) | 1 | yes — pre-existing, from `core/03` |

This is why the plan's acceptance criterion 1 cannot be met literally; see Deviations 2.

## Self-check table

| Check | Result | Evidence |
| --- | --- | --- |
| C1 files | PASS | 5 `SKILL.md` modified; no other file created outside `docs/ai-reports/` |
| C2 scope | PASS | plan names exactly these five; `update-action-builder` reported, not edited (it was `core/03`'s) |
| C3 syntax | N/A | Markdown documentation; no `.php` file touched, so 09 A7's single-file `php -l` has no subject |
| C4 autoload | N/A | no namespace path added |
| C5 DoD | PASS | §9 port-split, §10 transaction, §11 adapter and §D4/§D5 DoDs re-read against every rewritten section |
| C6 `core/` greps | N/A | `core/` not touched |
| C7 wiring | N/A | no Contract, route or provider changed |
| C8 schema | N/A | no schema |
| C9 leftovers | PASS | no `TODO` beyond the intentional "store route not defined yet" reminder the form-builder pairs with its placeholder action |
| C10 runtime | N/A | no runtime behaviour to exercise |
| C11 tests | PASS | "Paused per AGENTS.md", none claimed |
| C12 transactions | PASS | every Service snippet now uses `executeInTransaction()`; no manual transaction API survives in any skill |
| C13 boundaries | PASS | no `Core\`/`App\`/`Framework\` file touched; the rewritten repository tasks now teach the correct split and the `Wp` prefix |
| C14 file existence | PASS | all five paths confirmed present via `ls`/`grep` before editing; no file created |
| C15 rule-to-skill sync | PASS | A5b ran; `STALE_SKILLS_DETECTED: none`, with the negation check above as evidence |

## Commands run

```
git check-ignore -q .claude/rules .claude/skills docs/ai-reports     # exit 1 = tracked
grep -rn "register_rest_route" --include=*.php app/ routes/ core/   # 0 hits
grep -rn "register_rest_route" framework/                            # adapters only
grep -n "public function create" -A 25 core/Company/.../WpCompanyWriteRepository.php
grep -n "function paginate\|getFilteredCompanies" -A 45 core/Company/.../WpCompanyReadRepository.php
grep -n "protected function" core/Shared/Infrastructure/Repositories/BaseWriteRepository.php
grep -rn "Route::resource" .claude/skills/                           # 6 hits, all prohibitions
grep -rn "02-framework-adapter-laravel" .claude/skills/ | wc -l      # 0
```

## Post-write verification of the rewritten claims

Every API the rewritten skills reference was re-read on disk after editing, not assumed (09 A2.3):

| Claim in the skills | Verified at |
| --- | --- |
| `SCREEN_SLUG`, `CREATE_SLUG`, `EDIT_SLUG`, `STORE_ACTION`, `UPDATE_ACTION`, `DESTROY_ACTION` exist and are **public** | `app/Http/Controllers/Admin/CompanyController.php:17-24` |
| `CAPABILITY` exists | `CompanyController.php:20` |
| `deleteRecord(int $id, string $table, string $action = 'delete'): bool` | `core/Shared/Infrastructure/Repositories/BaseWriteRepository.php:206` |
| `insert()` / `write()` / `findRecordForUpdate()` are `protected` | `BaseWriteRepository.php:93/144/247` |
| `PaginatedResult::fromSlice(iterable, int, int, int)`, `lastPage()`, `isBeyondLastPage()` | `core/Shared/Domain/DataObjects/PaginatedResult.php:129/201/255` |
| `CompanyRepositoryContract::delete(int $companyId): void` | `core/Company/Domain/Contracts/CompanyRepositoryContract.php:49` |

One inaccuracy found by this pass and corrected: the delete skill initially described
`DESTROY_ACTION` as a `private const`. It is **public** — it has to be, because `routes/web.php`
references it (`:24`, used at `routes/web.php:84`).

## Decisions (D-IDs)

`D-2026-11-15-12` routing API correction (developer-confirmed). `D-2026-11-15-13` repository
vocabulary correction (developer-confirmed). `D-2026-11-15-14` plan step-ID says `core/06`, disk says
`core/05`. `D-2026-11-15-15` acceptance criterion 1 is unsatisfiable by a correct rewrite. Full text
in `DECISIONS.md`.

## Deviations

1. **Step ID.** The plan headings read `core/06-remaining-skills-cleanup`, but no `core/05` report or
   PROJECT-LOG row exists — `core/04` was the previous step. Following "EXISTING FILES WIN / never
   reuse an ID" and keeping the PROJECT-LOG sequence unbroken, this step is recorded as **`core/05`**
   and the plan's `06` is reported as a typo (D-2026-11-15-14). Flagged rather than silently
   renumbered.
2. **Acceptance criterion 1 reinterpreted.** The plan requires a grep for `Route::resource` across
   `.claude/skills/` to return **0 hits**. A correct rewrite cannot satisfy this: the checklist item
   that stops an agent using it must *name* it ("No `Route::resource()`"). Six such enforcement lines
   exist, one per skill. The criterion was met by its **intent** — zero hits that *instruct*
   (mechanically verified) — not by its literal count. Criterion 2
   (`02-framework-adapter-laravel.txt` → 0) **is** met literally. See D-2026-11-15-15.
3. **Two plan instructions overridden**, both with the developer's explicit agreement, because both
   would have introduced new defects: the routing API (D-2026-11-15-12) and the repository
   vocabulary (D-2026-11-15-13).
4. **Three defect classes fixed beyond the plan's three** — Blade, port/shape mismatch, and the
   `Collection`/paginator shape. The plan's goal ("align them fully with the WordPress `$wpdb` and
   CQRS architecture") and its third acceptance criterion name the outcome, so these are in the
   step's spirit and were authorised by that goal rather than invented.

## Deferred and open items

- **Nothing is committed.** `git add`/`commit` is a forbidden A7 operation, so `core/01`–`core/05`
  still exist only on this machine (`.gitignore` was fixed in `core/04`, which makes them
  committable — the developer must commit).
- `delete-action-builder`'s delete-rule and file-cleanup paths are rule-derived: the live
  `CompanyCommandService::deleteCompany()` takes the id and has **no** Entity behaviour, no event and
  no file cleanup, so those parts of the skill are correct per AGENTS.md §7/08 but unexercised by the
  live code.
- `index-action`'s filter/pagination section is written against `CompanyFilter` +
  `PaginatedResult::fromSlice`; a module with no filterable columns needs the COUNT/LIMIT query
  without the filter fields — noted in the skill, not yet proven on a second module.
- Steps `01`/`02` items unchanged: five dead Eloquent adapters, `findRecordForUpdate()` unverified at
  runtime (`FakeWpdb` cannot parse `FOR UPDATE`).

## Tests

Paused per AGENTS.md. No test written, scaffolded, required or run.

## Next step

Commit `core/01`–`core/05`, or resume the per-module repair of the five dead Eloquent adapters
(`core/01` D-2026-11-08 item).
