# Step 03 — Skill Files Cleanup (`core/03-skill-files-cleanup`)

**Status:** DONE_WITH_NOTES
**Date:** 2026-11-15
**Branch:** `wpdb`
**Plan:** `docs/ai-plans/action_plan_update_skills.md`
**Previous steps:** `01-repository-split-refactor.md`, `02-repository-rules-cleanup.md`

## Summary

Rewrote the two skills that still described a Laravel Eloquent project —
`module-scaffolder` and `update-action-builder` — so they describe this WordPress `$wpdb`
codebase. Beyond the `BaseRepository` rename the plan named, both skills were found to be
stale in ways that would have produced wrong code: they cited two rule files that do not
exist, mandated `Route::resource()` (WordPress has no such routing), described Blade
templates (this project uses plain `views/*.php`), and prescribed an inline
`TransactionManagerContract::run()` shape that AGENTS.md §10 forbids in favour of a shared
`executeInTransaction()`.

The step is DONE_WITH_NOTES for two reasons: the plan's mandated `system:skill-updater` tool
does not exist (the developer authorised direct edits), and **five further skills carry the
same two defects** — the developer widened scope to "fix all staleness" in the two named
skills, not to all seven.

## Inputs used

`docs/ai-plans/action_plan_update_skills.md`; `docs/ai-reports/02-repository-rules-cleanup.md`
(D-2026-11-15-06, which identified these two skills); both skill files; and the live code used
as the reference for every rewritten claim: `CompanyCommandService`,
`WpCompanyWriteRepository`, `WpCompanyReadRepository`, `routes/web.php`, `views/company/`.

## Rules and skills applied (PROCESS GUARD, before editing)

- §2 MODE DETECTION — documentation only; no `core/`, `app/`, `framework/` or `vendor/` path
  touched. Verified by timestamp (below).
- §10 TRANSACTIONS — the rewritten reference flow now matches the mandated DRY form
  (`executeInTransaction()`, `run()` inside it), not the inline shape the old skill showed.
- §7 / 08 — the events guidance was rewritten to match the rule exactly: release from the
  behaviour-method Entity BEFORE persisting, dispatch after commit, never from the
  repository-returned Entity.
- §P1 / MODE DETECTION — the module provider guidance corrected from `AppServiceProvider` to a
  per-module `{ModuleName}ServiceProvider` listed in `config/app.php`.
- 09 A2.5 — only the two named skills edited; the five others recorded, not touched.
- 09 A6 C1/C2/C3 — verified against the real code before claiming anything.
- Tests: **Paused per AGENTS.md.** No test written, scaffolded or run.

## The `system:skill-updater` tool does not exist

The plan states: *"You must use the `system:skill-updater` tool … Do NOT edit these files
manually"*, and makes it the first acceptance criterion. Four checks, all negative:

| Check | Result |
| --- | --- |
| `find` for `*skill-updater*` on disk | not found |
| `.claude/skills/` listing | 7 skills, no updater |
| `~/.claude/skills/` | does not exist |
| Grep for `skill-updater` in `.claude/` and `~/.claude/` | only match is this session's own transcript (the plan being read back) |

Raised with the developer, who chose "manual edit + fix all staleness". Recorded as
D-2026-11-15-07.

## Files modified

Both are **untracked local files** — `.claude/` is gitignored, so these edits are on disk but
invisible to `git diff`.

### `.claude/skills/module-scaffolder/SKILL.md` (134 lines)

| Where | Change |
| --- | --- |
| frontmatter | "repository skeleton" -> "write/read repository skeletons" |
| L14 | `09-autonomous-execution.txt` -> `09-autonomous_execution.txt` (the real filename uses an underscore) |
| step 1 | `02-framework-adapter-laravel.txt` -> `02-framework-adapter-wordpress.txt`; Shared-pieces list now names the three base classes |
| step 2 | Module tree rewritten: WRITE port in `Domain/Contracts/`, READ port + both inbound service contracts in `Application/Contracts/`, and **two** adapters (`Wp{ModuleName}WriteRepository extends BaseWriteRepository`, `Wp{ModuleName}ReadRepository extends BaseReadRepository`) |
| step 7 | Infrastructure generation rewritten for both adapters, with the `?wpdb $wpdb = null` ctor and per-call `TABLE_SUFFIX` |
| step 8 | Eloquent Collection guidance replaced with `ARRAY_A` + native `array_map()/array_filter()`, and an explicit ban on `collect()`/`Illuminate\` |
| step 9 | Wiring corrected to a per-module `{ModuleName}ServiceProvider` (P1) with the four bindings, plus the Shared-ports rule |
| checklist | Fixed the self-contradiction: the old list claimed the Entity *has* `$domainEvents`/`releaseEvents()` while step 3 says not to add them. Now: "a fresh scaffold has neither". Added the two-adapter check and the provider check |

### `.claude/skills/update-action-builder/SKILL.md` (271 lines)

| Where | Change |
| --- | --- |
| frontmatter description + L10 | "Laravel Clean/Hexagonal" -> this WordPress plugin; `Model/DB` -> `$wpdb` |
| L12 | `02-framework-adapter-laravel.txt` -> `-wordpress.txt`; `09-autonomous-execution.txt` -> `_` |
| lifecycle diagram | `Route::resource()` -> explicit named route in `routes/web.php`; `BaseRepository` + "raw Eloquent: `lockForUpdate()`, `fill()`, `isDirty()`, `save()`" -> `BaseWriteRepository` + prepared `$wpdb` SQL; the transaction box now shows `executeInTransaction()` wrapping `run()`; `back()->with(...)` -> flash keys |
| §1 FormRequest/Route | `Route::resource()` -> explicit `Route::match`/`post` with `->name()`; capability check instead of policy/gate; nonce/capability requirement added |
| §3 TOCTOU | `BaseRepository::findRecordForUpdate()` / "applies `lockForUpdate()`" -> `BaseWriteRepository::findRecordForUpdate(int $id, string $table, string $action)` with the real `SELECT … LIMIT 1 FOR UPDATE` |
| §4 Repository | "never returns an Eloquent Model" -> never returns a raw `$wpdb` row; added the `last_error` distinction between a failed read and a missing row; duplicate translation now names the `duplicateException()` hook; added the prepared-statement and constants rule |
| §5 (was "BaseRepository") | **Rewritten entirely.** The old section taught `isDirty()`, `fill()`/`$fillable`/`$guarded` — all Eloquent. Now: the two-adapter split, why `write()` builds its own `SET` (nulls must compile to `NULL`), `findRecordForUpdate()` returning a raw row, per-call table constants + lazy `?wpdb`, and an explicit Eloquent ban |
| §6 View | `edit.blade.php` + `{{ route(...) }}`/`@csrf`/`@method` -> `views/{module}/edit.php` plain PHP with `e()`, hidden id, nonce field; added the `!empty($errors[...])`/`old()` conventions |
| Build Guidance 1/2/6/7 | Route, `authorize()`, repository and service steps rewritten to the real shapes |
| Reference code block | Replaced the inline-`run()` flow with the real `executeInTransaction()` pattern modelled on `CompanyCommandService`, plus four notes on translator keys, guard-only in-closure failures, file cleanup timing, and post-commit dispatch |
| Review Workflow | Read order `Repository -> BaseRepository` -> `Write Repository -> BaseWriteRepository` |

No file created; none deleted.

## Self-check

| Check | Result | Evidence |
| --- | --- | --- |
| Acceptance — both skills updated | PASS | Both edited; 134 and 271 lines; frontmatter intact; code fences balanced |
| Acceptance — skills instruct the new CQRS hierarchy | PASS | `BaseWriteRepository`/`BaseReadRepository` appear in the module tree, step 7, step 9, the checklist, and update-action-builder §4/§5 and the lifecycle diagram |
| Acceptance — skills instruct `$wpdb` helpers, not Eloquent | PASS | `findRecordForUpdate`, `write`, `insert` named with real signatures; Eloquent methods now appear only as prohibitions and in the sweep verification |
| Sweep — `BaseRepository` in any skill | PASS | `grep -rn BaseRepository .claude/skills/` -> **zero** |
| Sweep — stale rule filenames in the two edited skills | PASS | `02-framework-adapter-laravel` / `09-autonomous-execution` -> zero in both |
| C2 scope — only the two named skills edited | PASS (with note) | No other skill touched; five others found stale and reported (D-2026-11-15-08) |
| Timestamps — no code touched | PASS | Only the two `SKILL.md` files changed |
| Tests | PASS | "Paused per AGENTS.md" — none written, scaffolded or run |

### Verification of the rewritten claims against real code

Every substantive claim was taken from the live codebase, not from memory:

| Claim in the skill | Source verified |
| --- | --- |
| `findByIdForUpdate()` implemented via `findRecordForUpdate($id, TABLE_SUFFIX, $action)` | `WpCompanyWriteRepository::findByIdForUpdate()` |
| Service uses `executeInTransaction(operation:, logMessage:, fallbackMessageKey:, fallbackErrorCode:, context:)` | `CompanyCommandService:105-138, 182+` |
| Entity behaviour is `applyChanges(...)` returning a new instance, with `clear*` flags | `CompanyCommandService:115-125` |
| Routes are explicit + named, e.g. `Route::post('companies/update', ...)->name('company.update')` | `routes/web.php:64-65` |
| Views are `views/{module}/*.php`, not Blade | `views/company/edit.php` exists; no `.blade.php` anywhere |
| Release events BEFORE persist, dispatch after commit | confirmed by the note itself (D-2026-11-15-01 detail) — this step did not execute it, and no service in the repo dispatches events on the update path yet, so the guidance is rule-derived and marked as such |

## Decisions

### D-2026-11-15-07 — the mandated `system:skill-updater` tool does not exist

**Type:** RULE CONFLICT (brief vs. environment).
**Situation:** the plan requires `system:skill-updater` and forbids manual edits; the tool is
absent (four checks, all negative).
**Decision:** raised it with the developer, who authorised direct edits and widened scope to
fix all staleness in the two named skills.
**Basis:** A9 precedence 1 (explicit developer instruction) over the brief's tool mandate; H5
would otherwise have halted.
**Impact:** both skills edited with `Edit`; acceptance criterion 1 reinterpreted as "edited
directly, no skill-updater available".
**Proposed AGENTS.md change:** none.
**Status:** OPEN.

### D-2026-11-15-08 — five further skills carry the same two defects

**Type:** RULE ERROR.
**Situation:** the acceptance sweep found `delete-action-builder`, `edit-form-builder`,
`form-builder`, `index-action` and `store-action-builder` each citing
`02-framework-adapter-laravel.txt` (does not exist) and `09-autonomous-execution.txt` (wrong
separator), and each mandating `Route::resource()` (WordPress has no such routing) in 3 places.
**Options:** fix them now; or record and defer.
**Decision:** **not edited.** The plan names two skills; the developer's widening was "fix all
staleness" *in those two*. Editing five more unprompted is the scope expansion §6 SCOPE and
A2.5 forbid.
**Basis:** §6 SCOPE, 09 A2.5.
**Impact:** five skills still instruct agents to use routing and rule files that do not exist.
Each needs the same treatment this step gave the two named ones — a rewrite against the real
code, not a search-and-replace.
**Proposed AGENTS.md change:** none — the fix belongs in a follow-up brief naming the skills.
**Status:** OPEN — needs a follow-up step.

## Deviations

1. **Used direct edits**, not the mandated `system:skill-updater` (D-2026-11-15-07).
2. **Scope widened to full staleness** in the two named skills, on the developer's instruction
   — beyond the plan's list of `BaseRepository`-only replacements.
3. **§5 of `update-action-builder` was replaced wholesale.** It was titled `BaseRepository` and
   taught `isDirty()`, `fill()`, `$fillable`, `$guarded` — nothing in it survived the move to
   `$wpdb`, so a rename would have left four false rules.
4. **The reference Command Service was rewritten, not renamed.** The old block showed an inline
   `run()` with per-method catch blocks; AGENTS.md §10 mandates one shared
   `executeInTransaction()`. Keeping the old shape would have contradicted the rule the skill
   cites.

## Deferred and open items

- Five skills with the same defect (D-2026-11-15-08).
- `.claude/` is gitignored: this and steps 01–02 exist only on this machine. A fresh clone
  carries none of the rule or skill fixes.
- `update-action-builder`'s event-dispatch guidance is rule-derived: no module currently
  dispatches events on an update path, so it is unexercised by the live code. It follows 08
  exactly.
- Steps 01/02 items unchanged: five dead Eloquent adapters, `findRecordForUpdate()` unverified
  at runtime (`FakeWpdb` cannot parse `FOR UPDATE`).

## Tests

Paused per AGENTS.md. No test written, scaffolded, required or run.

## Next step

Apply the same treatment to the five remaining skills (D-2026-11-15-08), or resume the
per-module repair of the five dead adapters.
