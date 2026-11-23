# Decisions

Decisions taken while executing a plan that AGENTS.md and the rule files did not
cover. Each records what was decided, why, and the proposed AGENTS.md change.

---

## D-2026-11-14-01 — `companies.name` is unique (business rule, new)

**Decided:** `companies.name` carries a UNIQUE index. Two companies may not share
a name. Landed as `database/migrations/2026_10_31_222317_make_companies_name_unique.php`.

**Why:** `CompanyDuplicateException` and the write repository's duplicate
translation already existed, but `companies.name` was declared `->index()` when
the table was created, so nothing enforced uniqueness — the conflict path was
detection with no constraint behind it. The developer confirmed the constraint
should be live.

**Consequences the developer accepted:**

- Applying it **refuses an install that already holds duplicate names.** The
  statement fails; duplicates are not removed, because deleting customer rows is
  not a migration's decision to make.
- The comparison follows the column's collation (`utf8mb4_unicode_ci`), so `Acme`
  and `acme` collide. This matches P5 (natural keys, case-insensitive).
- A submission that previously succeeded can now be rejected.

**Not covered by any existing rule.** Rules 05/06 govern ports and entities; P5
covers matching seeded rows by natural key, not *declaring* one. A uniqueness rule
enforced by a database index belongs in the schema, and this is the first such
rule in this project that is not a technical constraint (foreign keys, join
tables).

**Proposed AGENTS.md change:** add to PROJECT CONVENTIONS, after P5:

> **P17 NATURAL-KEY UNIQUENESS IS A SCHEMA CONSTRAINT:** a business rule stating
> that a value identifies one row (a company name, an account email) is enforced
> by a UNIQUE index, not only by an application check. The adapter translates the
> rejection into the module's conflict exception; the Service catches that and
> returns a failure Result. A rule enforced only in application code races between
> two concurrent writers.

---

## D-2026-11-14-02 — the `$wpdb` duplicate check matches message text

**Decided:** `BaseRepository::throwIfDuplicate()` recognises a rejected unique key
by testing `$wpdb->last_error` for the SQLSTATE `23000` or MySQL's
`Duplicate entry` phrase, rather than by an error number.

**Why:** `$wpdb` exposes no error code. WordPress reads `mysqli_errno()` into a
local variable for its own reconnect logic (`wp-includes/class-wpdb.php:2270-2273`)
and never stores it on the object. Verified in the WordPress source rather than
assumed. `last_error` is the only channel available.

**Trade-off accepted:** the Fluent builder's path (`WpFluentQueryBuilder`) uses
error **1062**, which is exact; this path matches text, which is localised and
reworded between MySQL versions. The check is confined to one method so the
weakness has one home.

**Proposed AGENTS.md change:** note under D7 that `$wpdb` repositories detect
duplicates from `last_error`, since no error code is exposed, and that this is the
one sanctioned exception to "never match on driver message text".

---

## D-2026-11-14-03 — a shared `BaseRepository` may not demand a connection

**Decided:** `BaseRepository::__construct()` takes `?wpdb $wpdb = null` and
resolves the global lazily, rather than requiring a handle.

**Why:** the container builds every repository during a provider's `register()`,
which runs before WordPress has loaded `$wpdb`. A required handle would make every
repository unbuildable at that point. This follows the pattern
`LeadWriteRepository` and `LeadNoteWriteRepository` already establish.

**Proposed AGENTS.md change:** none. This is an application of the existing
"Reading `framework/` … is always allowed" plus the DI mandate; it is recorded here
only because it is the reason the shared base changed shape.

---

## D-2026-11-14-04 — five `BaseRepository` subclasses are knowingly left broken

**Decided:** `ActivityWriteRepository`, `AuthWriteRepository`,
`ContactWriteRepository`, `DealWriteRepository` and `PipelineWriteRepository` were
not updated for the new base constructor, so they cannot be constructed.

**Why:** the plan names three Company files and no others, and AGENTS.md's STRICT
SCOPE ADHERENCE forbids refactoring code the prompt did not name. All five were
**already** dead: they extend `BaseRepository` with `parent::__construct($model)`
against Eloquent models, `app/Models/` does not exist, and two import
`Illuminate\Database\QueryException` / `Illuminate\Support\Facades\DB`.

**Consequence:** this is a real, separate JOB C task, not a completed refactoring.
It is recorded here so it is not mistaken for done.

**Proposed AGENTS.md change:** none. This is a scope decision, not an architectural
one.

---

## D-2026-11-15-01 — the five dead adapters are re-pointed but not repaired

**Step:** `core/01-repository-split-refactor`.
**Type:** RULE CONFLICT (brief vs. A10/H2).

**Situation:** the brief instructs "fix the 5 dead adapters" by removing the Eloquent model
from their constructors and aligning them with the `$wpdb` pattern. They are not
constructor-broken. Their bodies are Eloquent calls — `fill()`, `save()`, `toArray()`,
`refresh()`, `getAttributes()`, `DB::transaction()`, pivot `syncWithoutDetaching()` — and
both `app/Models/` and `vendor/illuminate/` do not exist in this plugin. Removing the
constructor argument leaves five classes that still reference five unresolvable model
classes and an uninstalled package. `ContactWriteRepository` alone has 17 Eloquent call
sites, plus pivot read/write and transaction semantics to rebuild by hand.

**Options considered:** (a) rewrite all five as `$wpdb` adapters; (b) re-point the
inheritance only and leave the bodies dead; (c) delete them as unreferenced dead code;
(d) leave them entirely untouched.

**Decision:** (b), after the developer chose "skip them, ship the rest" when the conflict
was raised. Their `extends` and `use` name `BaseWriteRepository` so the rename leaves no
dangling reference — without that, deleting `BaseRepository` would have moved their failure
from first-construct to class-load, widening it. Bodies unchanged.

**Basis:** A9 precedence 1 (the developer's explicit instruction in the current brief, within
scope) over A10-H2; §6 SCOPE forbids inventing five query implementations the brief never
described.

**Impact:** five files, two lines each; fully reversible. No runtime change — nothing binds
any of the five.

**Proposed AGENTS.md change:** none. This is a scope decision, not an architectural one.

**Status:** OPEN — the real repair is a separate JOB C task, one module at a time.

---

## D-2026-11-15-02 — `AbstractWpRepository` replaces `BaseRepository` as the D4 base

**Step:** `core/01-repository-split-refactor`.
**Type:** RULE CONFLICT (§4 D4 vs. the brief).

**Situation:** §4 D4 read "the abstract base stays `BaseRepository`". The brief renames that
class and installs `AbstractWpRepository` as the root of a three-class hierarchy.

**Options considered:** keep D4 and decline the rename; or follow the brief and rewrite D4.

**Decision:** followed the brief. D4 rewritten to name `AbstractWpRepository` as the root,
extended by `BaseWriteRepository` and `BaseReadRepository`.

**Basis:** A9 precedence 1 — the brief names this change explicitly. A16's "no rule file,
AGENTS.md, skill or plan edited" is overridden by the brief authorising exactly these files.

**Impact:** `AGENTS.md` §4, §9, §14 and `03`/`04`/`06`; reversible.

**Proposed AGENTS.md change:** none beyond the edit itself; the developer should confirm the
new D4 wording.

**Status:** OPEN.

---

## D-2026-11-15-03 — `$table` is a per-call argument, not a constructor property

**Step:** `core/01-repository-split-refactor`.
**Type:** GAP.

**Situation:** the brief's new method signatures take `string $table` per call. The old `03`
rule described a base whose constructor took `$table` once and held it as
`protected readonly string $table` — an API the real `BaseRepository` never had.

**Decision:** kept the brief's per-call shape, which is what the live code already did
(`$this->insert($values, self::TABLE_SUFFIX, $action)`), and rewrote the `03` BASE REPOSITORIES
section to describe the three classes as they actually are, including the ctor taking only
`?wpdb $wpdb = null`.

**Basis:** A9 precedence 5 (existing codebase convention, verified by reading) plus the brief.

**Impact:** `03` only. Reversible.

**Proposed AGENTS.md change:** none.

**Status:** OPEN.

---

## D-2026-11-15-04 — four rule files still name the deleted `BaseRepository`

**Step:** `core/01-repository-split-refactor`.
**Type:** RULE ERROR.

**Situation:** `02-framework-adapter-wordpress.txt` (lines 27, 41, 63, 65, 77),
`05-rich-domain-and-services.txt` (lines 136, 139), `09-autonomous_execution.txt` (C13) and
`11-fluent-database.txt` (lines 2, 8, 12) still reference `BaseRepository`, which this step
deleted.

**Options considered:** edit them now; or record and defer.

**Decision:** **not edited.** The brief named exactly four files (`AGENTS.md`, `03`, `04`,
`06`); A2.5 forbids editing rule files the brief did not name. Recorded here so the next
brief fixes them.

**Basis:** A2.5 and §6 SCOPE.

**Impact:** those four rule files now contain a dangling class name. This is the **one known
inconsistency left by the step**, and the reason its status is DONE_WITH_NOTES.

**Proposed AGENTS.md change:** none — the fix belongs in a follow-up brief that names those
four files.

**Status:** OPEN — needs a follow-up step.

---

## D-2026-11-15-05 — the cleanup brief's file names are paraphrases

**Step:** `core/02-repository-rules-cleanup`.
**Type:** AMBIGUOUS BRIEF.

**Situation:** the brief names `02-framework-adapter-wp.txt`, `05-error-handling.txt` and
`11-test-strategy.txt`. None of those files exists. The real files are
`02-framework-adapter-wordpress.txt`, `05-rich-domain-and-services.txt` and
`11-fluent-database.txt`.

**Options considered:** halt for confirmation; or resolve from the hit counts.

**Decision:** resolved without halting. Each of the brief's estimates (5, 2, 3) matched exactly
one real file's `BaseRepository` count, and `09-autonomous_execution.txt` with "specifically
C13" named its target precisely.

**Basis:** 09 A2.2 (a minor ambiguity never halts; take the safest standard path and record it)
and A3 (merely unclear = AMBIGUOUS BRIEF, not H7).

**Impact:** none on the result; the correct four files were edited.

**Proposed AGENTS.md change:** none.

**Status:** OPEN.

---

## D-2026-11-15-06 — six `BaseRepository` references remain in two skills

**Step:** `core/02-repository-rules-cleanup`.
**Type:** RULE ERROR.

**Situation:** the acceptance sweep for step 02 found `BaseRepository` in
`.claude/skills/module-scaffolder/SKILL.md` (lines 40, 87) and
`.claude/skills/update-action-builder/SKILL.md` (lines 70, 114, 139, 267). Line 87 instructs
scaffolding "a `final class` extending `BaseRepository`" — a class deleted in step 01. Line 114
tells the agent to verify `findRecordForUpdate()` exists in `BaseRepository` and to stop if it
does not.

**Options considered:** edit them now; or record and defer.

**Decision:** **not edited.** The brief names four rule files and describes a "documentation and
rule consistency pass" over those files; 09 A2.5 forbids editing skills the brief did not name.

**Basis:** 09 A2.5 and §6 SCOPE.

**Impact:** a scaffolding skill would today instruct an agent to extend a deleted class. Both
skills are also Laravel-flavoured and independently stale: `module-scaffolder` line 38 cites
`.claude/rules/02-framework-adapter-laravel.txt`, which does not exist in this repo, and
`update-action-builder` line 70 describes "raw Eloquent: `lockForUpdate()`, `fill()`,
`isDirty()`, `save()`". Repairing them is a review of both skills against the WordPress rule
set, not a rename.

**Proposed AGENTS.md change:** none — the fix belongs in a follow-up brief naming the skills.

**Status:** OPEN — needs a follow-up step.

---

## D-2026-11-15-07 — the mandated `system:skill-updater` tool does not exist

**Step:** `core/03-skill-files-cleanup`.
**Type:** RULE CONFLICT (brief vs. environment).

**Situation:** the plan requires `system:skill-updater` and forbids manual edits, making it the
first acceptance criterion. Four checks were all negative: no `*skill-updater*` on disk; not in
`.claude/skills/`; `~/.claude/skills/` does not exist; and the only `skill-updater` string in
`.claude/` or `~/.claude/` is this session's own transcript, i.e. the plan being read back.

**Options considered:** halt under H5; or ask the developer to authorise direct edits.

**Decision:** raised it with the developer, who chose "manual edit + fix all staleness",
widening scope from the plan's `BaseRepository`-only replacements to a full staleness review of
the two named skills.

**Basis:** 09 A9 precedence 1 (the developer's explicit instruction in the current brief,
within scope) over the brief's tool mandate.

**Impact:** both skills edited directly with `Edit`. Acceptance criterion 1 is reinterpreted as
"edited directly, no skill-updater available".

**Proposed AGENTS.md change:** none.

**Status:** OPEN.

---

## D-2026-11-15-08 — five further skills carry the same two defects

**Step:** `core/03-skill-files-cleanup`.
**Type:** RULE ERROR.

**Situation:** the acceptance sweep found `delete-action-builder`, `edit-form-builder`,
`form-builder`, `index-action` and `store-action-builder` each citing
`02-framework-adapter-laravel.txt` (does not exist) and `09-autonomous-execution.txt` (wrong
separator; the real file uses an underscore), and each mandating `Route::resource()` —
three occurrences per skill — which WordPress does not have.

**Options considered:** fix them now; or record and defer.

**Decision:** **not edited.** The plan names two skills, and the developer's widening applied to
those two. Editing five more unprompted is the scope expansion §6 SCOPE and 09 A2.5 forbid.

**Basis:** §6 SCOPE and 09 A2.5.

**Impact:** five skills still instruct agents to use routing and rule files that do not exist in
this project. Each needs the same treatment this step gave the two named ones — a rewrite
against the real code, not a search-and-replace.

**Proposed AGENTS.md change:** none — the fix belongs in a follow-up brief naming the skills.

**Status:** OPEN — needs a follow-up step.

---

## D-2026-11-15-09 — the acceptance command cannot pass, and the HALT template is coupled to section numbers

**Step:** `core/04-agents-and-rules-upgrade`.
**Type:** RULE CONFLICT (brief says two things that cannot both be true).

**Situation:** the plan's acceptance requires `git check-ignore .claude/rules .claude/skills`
to "return no matches", and the same instruction requires that local cache/temp files inside
`.claude/` be ignored. Both cannot hold. A negation rule is the only way to keep `.claude/*`
ignored while exposing the two subdirectories, and `git check-ignore` **prints the deciding
pattern even when it is a negation** (`!.claude/rules/`, exit 0). So any correct solution
produces stdout naming the rules the plan wants silent — the criterion is unsatisfiable as
written, and a literal reading would push the next agent toward `.claude/rules/`-only patterns
that anchor wrongly against nested skill paths.

A second, smaller instance of the same coupling surfaced in the plan's HALT template: it tells
the agent to "Quote the exact section in `AGENTS.md`". The new Startup Protocol is placed
*above* §1, but the heading was deliberately **not** renamed to `§0` — `CLAUDE.md` and the rule
files cite sections by number (`§2`, `§6`), so a renumber would create a second, less visible
staleness the very rule being added exists to prevent.

**Options considered:** (a) drop `.claude/*` and expose the whole directory, so `check-ignore`
is silent — but then local settings and cache become committable, against the plan's own
sentence; (b) keep `.claude/*` + negations and read the criterion by exit code; (c) ignore
`.claude/` and un-ignore both subdirectories individually, which hits the "cannot re-include
inside an excluded directory" rule and leaves rules/skills untracked again — the exact bug.

**Decision:** (b). Verified by exit code and behaviour: `git check-ignore -q` returns 1 for all
three paths, and 18 `.claude/` files plus 11 `docs/` files are addable. The Git Tracking Guard
was written against that meaning rather than against empty output.

**Basis:** 09 A9 precedence 3 (specific rule files over a plan's acceptance wording; here the
plan's own two requirements conflict, so the one that preserves the stated intent — tracked
rules/skills, ignored local junk — wins) with the RULE CONFLICT recorded.

**Impact:** `.gitignore` carries a clarifying comment so the next agent does not "fix" the
negations back into the untracked state. The report states the deviation. Fully reversible.

**Proposed AGENTS.md change:** none needed — this is a plan-wording defect. The §0 guard already
states the check as "MUST return no output **for them**", which is the exit-code meaning.

**Status:** OPEN — worth correcting in the plan template the next time one is written.

---

## D-2026-11-15-10 — 30 stale-skill hits reported, not fixed

**Step:** `core/04-agents-and-rules-upgrade`.
**Type:** GAP (scope boundary).

**Situation:** the A5b sweep this step added was run against this step's own edits and returned
30 hits across five skills: `delete-action-builder` (6), `edit-form-builder` (5), `form-builder`
(5), `index-action` (5), `store-action-builder` (7), plus 2 residual hits in
`update-action-builder`. They are the same defect `core/03` recorded as D-2026-11-15-08 — rule
files that do not exist, `Route::resource()` which WordPress has no equivalent of, and `DB::`
facade references — now itemised with line numbers.

**Options considered:** fix all 30 now, using the new A5b rule as authority; or report them.

**Decision:** **reported, not edited.** A5b authorises fixing hits *inside a step's write scope*;
this step's scope is `.gitignore`, `AGENTS.md` and rule `09`. The five skills are outside it,
and `core/03` already established — with the developer's explicit agreement — that they need a
brief naming them, not an unprompted edit.

**Basis:** §6 SCOPE; 09 A2.5 and A5b's own "outside it: do NOT edit".

**Impact:** five skills still instruct agents to use routing, rule files and a database facade
this project does not have. Third consecutive step to report the list; the value added here is
the line numbers. This is exactly the failure mode A5b was written to surface.

**Proposed AGENTS.md change:** none — the fix belongs in a follow-up brief naming the five skills.

**Status:** OPEN — needs a follow-up step.

---

## D-2026-11-15-11 — `delete-action-builder` cites a principle section that does not exist

**Step:** `core/04-agents-and-rules-upgrade`.
**Type:** RULE ERROR (in a skill).

**Situation:** `delete-action-builder/SKILL.md:5` cites `02-framework-adapter-laravel.txt` for
"the project's rich-domain principle" and the section it names has no counterpart in the real
`02-framework-adapter-wordpress.txt`. This is a distinct defect from the two `core/03` tabled
(citation to a nonexistent *file*, and Laravel-only *routing*): it is a citation to a nonexistent
*section* of an existing file, so a search-and-replace of the filename would leave the reference
still wrong.

**Options considered:** correct the citation now; or record it with the rest.

**Decision:** recorded, not corrected — `delete-action-builder` is outside this step's scope for
the same reason as D-2026-11-15-10, and correcting a citation requires reading rule `02` to find
which section the principle actually lives in, which is skill-rewrite work.

**Basis:** §6 SCOPE; 09 A2.5.

**Impact:** agents running that skill are pointed at a section that does not exist, so the
principle is effectively unread. One row in the next skill-cleanup brief.

**Proposed AGENTS.md change:** none.

**Status:** OPEN — folds into the follow-up brief with D-2026-11-15-10.

---

## D-2026-11-15-12 — the plan's prescribed routing API does not exist in this project

**Step:** `core/05-remaining-skills-cleanup`.
**Type:** RULE CONFLICT (plan instruction vs. verified codebase).

**Situation:** the plan orders `Route::resource()` replaced with "WordPress REST API routing
(`register_rest_route()`)". Two checks contradict it. `register_rest_route()` appears **zero times**
in `app/`, `routes/`, `core/` — it is called inside `framework/Routing/Adapters/RestApiRouterAdapter.php:119`
and `framework/Routing/Router.php:238/266`, i.e. it is an implementation detail the framework's
`Route::` facade wraps. And the actions in question (delete, store, update) are **web** routes in
`routes/web.php` that end in a redirect, not REST endpoints: `Route::post('companies/delete', ...)`
with `->withOptions(['action' => ...])` and `->middleware(['nonce:' . ...])`. A skill teaching
`register_rest_route()` would produce code no module in this project contains, and would misplace a
web form handler onto a REST route.

**Options considered:** (a) follow the plan literally and teach `register_rest_route()`;
(b) rewrite against the `Route::` facade as `routes/web.php` actually uses it; (c) teach both with a
note on when REST is right.

**Decision:** (b), after raising it with the developer, who chose "Route:: facade, per real code".
REST endpoints already exist for reads (`routes/api.php`, `LeadController`), so a future REST-route
skill has a real reference; this step's five skills are all web/admin screens.

**Basis:** 09 A9 precedence 1 (the developer's explicit instruction in the current brief) applied to
the plan's own conflict; §11 (adapters isolate framework detail) argues against teaching the raw
WordPress function in a delivery skill.

**Impact:** all five skills' routing sections rewritten against `routes/web.php` with a named live
cross-reference. Reversible; no code touched.

**Proposed AGENTS.md change:** none — the correction belongs in the plan template.

**Status:** OPEN — plan wording to fix if a similar brief is written.

---

## D-2026-11-15-13 — the plan's prescribed `$wpdb` helpers are not callable from a Service

**Step:** `core/05-remaining-skills-cleanup`.
**Type:** RULE CONFLICT (plan instruction vs. §9/§11 layering).

**Situation:** the plan orders Eloquent methods replaced with "our custom `$wpdb` helpers
(`findRecordForUpdate`, `write`, `insert`)". Those three exist — but as `protected` methods on
`BaseWriteRepository` (`core/Shared/Infrastructure/Repositories/BaseWriteRepository.php:247`, `:144`,
`:93`), which is **Infrastructure**. A Service that called them would be both a fatal visibility
error and a §9 boundary violation (a Command Service reaching past its port into the adapter's base
class). The live vocabulary is two-layered: the Service calls the **port**
(`CompanyRepositoryContract::delete(int $companyId): void`), and the adapter calls the helper
(`$this->deleteRecord($companyId, ...)`).

**Options considered:** (a) teach the helpers at every layer, as written; (b) teach the port in
Services and the helpers in adapters; (c) teach only the port, leaving helper mechanics to rule 03.

**Decision:** (b), after raising it with the developer, who chose "Service calls the port; adapter
uses the helpers". The skills' repository tasks now show both layers explicitly, which is also what
makes the CQRS split legible to an agent.

**Basis:** 09 A9 precedence 1 (developer's instruction in the brief, resolving the plan's conflict);
AGENTS.md §9 (port split) and §11 (ports and adapters).

**Impact:** every Service snippet uses the port; every adapter snippet extends
`BaseWriteRepository`/`BaseReadRepository` and uses the inherited helper. Also corrected
`delete(Entity): bool` → `delete(int): void` to match the live contract. Reversible.

**Proposed AGENTS.md change:** none.

**Status:** OPEN — plan wording to fix.

---

## D-2026-11-15-14 — the plan's step ID says `core/06`; disk says `core/05`

**Step:** `core/05-remaining-skills-cleanup`.
**Type:** GAP (numbering).

**Situation:** the plan's headings read `# STEP ID: core/06-remaining-skills-cleanup`, but no
`core/05` report or PROJECT-LOG row exists — the previous executed step was `core/04`. Taking `06`
would either skip a number or imply a `core/05` that was never run, breaking the PROJECT-LOG
sequence a fresh session resumes from (09 A14, "the filesystem is the truth").

**Options considered:** file as `core/06` to match the plan; file as `core/05` to match disk; ask.

**Decision:** filed as **`core/05`**, with the plan's `06` reported as a typo. A9 settles it without a
halt: the report layout is `<NN>-<step-slug>.md` and A11 requires "EXISTING FILES WIN / keep their
numbering", so continuing the existing 01→04 sequence is the recorded convention, and `06` would
leave a hole that reads as a lost step.

**Basis:** 09 A11 (report layout and numbering), A14 (disk is the truth), A9 Tier 2 (a gap the rules
settle).

**Impact:** the plan file keeps its `06`; the report, PROJECT-LOG row and this decision use `05`. If
the developer intended a real skipped step, the number is a one-line correction.

**Proposed AGENTS.md change:** none.

**Status:** OPEN — confirm the intended number.

---

## D-2026-11-15-15 — the acceptance grep cannot reach zero on a correct rewrite

**Step:** `core/05-remaining-skills-cleanup`.
**Type:** RULE CONFLICT (acceptance criterion vs. its own intent).

**Situation:** acceptance criterion 1 requires `grep -r "Route::resource" .claude/skills/` to return
**0 hits**. A skill that must stop an agent using `Route::resource()` has to name it: the checklist
line reads "No `Route::resource()`, and no direct `register_rest_route()` call". Six such lines exist
(one per skill, including the out-of-scope `update-action-builder`). Satisfying the criterion
literally would mean deleting the prohibitions — leaving five skills that no longer *instruct* the
wrong API but also no longer *warn* against it, which is weaker, not cleaner. Criterion 2
(`02-framework-adapter-laravel.txt` → 0 hits) is met literally and unambiguously.

**Options considered:** (a) delete the prohibition text to reach zero hits; (b) keep the prohibitions
and satisfy the criterion by intent; (c) keep one prohibition in a shared location and reference it.

**Decision:** (b). Verified mechanically rather than by eye: a script matched every `Route::resource`
occurrence against a negation word on the same line — **0 genuine instructions remain**. The criterion
is reported as met by intent, with the method of verification stated in the report so the claim is
checkable.

**Basis:** 09 A9 precedence 3 (the specific rule — §6 SCOPE and the anti-hallucination checklist
convention that a prohibitive item names its subject — over a plan's acceptance wording); 09 A2.3
(verify, don't assume) governs how the claim is evidenced.

**Impact:** none on the codebase; the report states the reinterpretation explicitly as Deviation 2.

**Proposed AGENTS.md change:** none — but a future acceptance criterion of this kind should read
"zero hits that *instruct* X", not "zero hits for X".

**Status:** OPEN.

---

## D-2026-11-15-16 — the pipeline needs a Scope Gate before Mapping

**Step:** `core/06-log-watcher-skill-enhanced`.
**Type:** GAP (the plan's pipeline omits a step it depends on).

**Situation:** the plan mandates a 4-step sequence, Log -> Mapping -> Source Inspection -> Confirm
Violation, and its own guiding principle says a mapping is a lead, not a verdict. Neither the
sequence nor the matrix contains any check on *whose code* failed. Running the pipeline against the
live `wp-content/debug.log` shows why that matters: of 6 Fatal blocks and 4 distinct messages, the
owners are a **deleted temp probe** (`.probe-del2.php`), a **third-party plugin**
(`wedevs-academy/vendor/autoload.php`), **WordPress core** (`wp-admin/includes/plugin.php`), and a
**PHP platform check** (`vendor/composer/platform_check.php`). Frame census: 7 WordPress core,
4 our `tests/manual/probe-*.php`, 2 our `vendor/`, 4 outside, and only 4 in scope
(`app/` 2, `framework/` 2 — the same single fault). Applying Mapping to the out-of-scope entries
would attribute a third-party plugin's missing dependency to `02` §5 (our DI bindings).

**Options considered:** (a) the plan's exact 4 steps, mapping every entry; (b) insert a Scope Gate
between Log Analysis and Mapping, and report non-owned frames as OUT OF SCOPE with their owning
path; (c) add the ownership note as advisory guidance inside Mapping.

**Decision:** (b), after raising it with the developer, who chose the gate. It is a hard step, not a
note: an entry whose trace never reaches `app/`, `core/`, `framework/` or `routes/` cannot be a
breach of this project's rules, and the skill says so plainly.

**Basis:** 09 A9 precedence 1 (the developer's explicit instruction in the current brief) resolving
a gap the plan does not cover. The plan's own guiding principle supports it — the matrix identifies
*where to investigate*, which is meaningless for code our rules do not govern.

**Impact:** the skill's pipeline is 5 steps; the report states this as Deviation 1. It also gives
the log's stale-frame case a home (a frame pointing at deleted code is classified, not mapped).

**Proposed AGENTS.md change:** none beyond the §15 added by this step, which already encodes the
gate.

**Status:** OPEN — the plan's STEP numbering (4 steps) and the skill (5) now differ by design.

---

## D-2026-11-15-17 — the plan's log path is cwd-dependent

**Step:** `core/06-log-watcher-skill-enhanced`.
**Type:** RULE ERROR (in the plan's command).

**Situation:** the plan gives `grep -n -C 5 "Fatal error" wp-content/debug.log`. That path is
correct from the WordPress root (`wp-lead-bridge/`) but wrong from this project's working directory,
the plugin root (`wp-content/plugins/wp-mini-laravel/`), where the real path is `../../debug.log`.
Verified: `wp-content/debug.log` exists from the WP root; there is **no** `debug.log` inside the
plugin directory. The failure mode is silent — a wrong path returns empty output, which reads
exactly like "the log is clean", producing a false negative that looks like a finding of no errors.

**Options considered:** (a) the plan's single path; (b) teach both paths with the cwd each assumes;
(c) resolve the path into a variable and require the cwd to be stated.

**Decision:** (b), after raising it with the developer, who chose both paths. The skill opens with
Step 0, a table of cwd -> path, and requires confirming the path resolves to a real file
(`ls -la`) before any conclusion.

**Basis:** 09 A9 precedence 1 (the developer's instruction in the brief); 09 A2.3 (verify, don't
assume) and A6 C1 (files planned exist) both argue for the check.

**Impact:** the skill has a Step 0 the plan does not describe; the report states it as Deviation 2.
The `AGENTS.md` §15 bullet records the same cwd rule.

**Proposed AGENTS.md change:** none.

**Status:** OPEN — plan command to correct if reused.

---

## D-2026-11-15-18 — the matrix's `mysqli_sql_exception` row does not match this log

**Step:** `core/06-log-watcher-skill-enhanced`.
**Type:** AMBIGUOUS BRIEF / RULE ERROR (matrix row vs. observed evidence).

**Situation:** the plan's matrix makes `mysqli_sql_exception` and SQL syntax errors a **High**
confidence pointer to `02` §7 (Persistence). The live log contains exactly one `mysqli` entry and it
is a **`PHP Warning`** — `mysqli_real_connect(): (HY000/2002): No connection could be made` — a
connection failure, not the row's exception class. Separately, `02` §7.6 requires adapters to
convert `$wpdb` failures into `\RuntimeException` without SQL in the message, which suggests a
`mysqli_sql_exception` should rarely if ever reach the log in this project at all.

**Options considered:** (a) rewrite the row to target `\RuntimeException` and connection fatals;
(b) keep the row as the plan wrote it and record the mismatch; (c) keep the row and add both
exception classes.

**Decision:** (b) — the row is kept verbatim as the plan specifies, and the mismatch is recorded
here and in the report. Renaming a matrix row the plan fixes by name is a scope expansion on a
stronger basis than I have; the developer can decide with this evidence in hand. The skill does not
overclaim: the row's rationale already says "direct `$wpdb`/query mismatch", and Mapping is
explicitly a lead, not a verdict.

**Basis:** §6 SCOPE (change nothing outside the prompt) and 09 A2.2 (a minor ambiguity is recorded,
not halted on).

**Impact:** the first matrix row may be aimed at an exception class this project's `02` §7.6 forbids
from escaping the adapter. A future revision should either add `\RuntimeException` from an adapter,
or point SQL failures at the connection-level fatal instead.

**Proposed AGENTS.md change:** none — the fix belongs in the skill's matrix, on the developer's
instruction.

**Status:** OPEN — needs the developer's call on the row's target.

---

## D-2026-11-15-19 — "any output" is an over-broad halt condition for the tracking guard

**Step:** `core/07-autonomous-fix-loop`.
**Type:** RULE CONFLICT (plan instruction vs. the mechanics of the command it names).

**Situation:** the plan requires `git check-ignore -v .claude/rules .claude/skills docs/ai-reports` to
be run and says: "If this command returns ANY output, HALT immediately." That is not a safe reading
of the command. `git check-ignore -v` prints the deciding pattern for a path *and still exits 0* for
an ignored path — but it also prints a line for a **negation** rule (`!.claude/rules/`) when the path
is in fact **tracked**. This project's `.gitignore` uses exactly that shape, established in `core/04`
to keep `.claude/rules/` and `.claude/skills/` tracked while ignoring local junk (D-2026-11-15-09).
So the plan's literal condition would halt on a healthy repository: the tracked case is the one that
prints.

**Options considered:** (a) apply the literal condition and halt; (b) drop the negations to silence
the command, which un-tracks the rules and skills again — the original bug; (c) run the plan's
command verbatim and judge the outcome by exit code, which is what the command actually reports.

**Decision:** (c). The command was run **verbatim** as the plan specifies, returned no output with
exit 1, and the step proceeded. The plan's halt condition is restated for future steps as: a **path
is ignored** iff `git check-ignore -q <path>` exits 0; printed lines alone are not a halt.

**Basis:** 09 A9 precedence 3 (a specific rule file over a plan's acceptance wording where the two
conflict) plus the RULE CONFLICT record required by A9; the same defect was already recorded once as
D-2026-11-15-09, so this is its second occurrence in a plan.

**Impact:** no file changed for this decision; the gate is documented in the report with the command
output and its exit code so the reading is checkable. Reversible.

**Proposed AGENTS.md change:** none — §0 already states the guard check correctly. The fix belongs in
the plan template, which has now produced this defect twice.

**Status:** OPEN — second occurrence; worth correcting the template before the next plan.

---

## D-2026-11-15-21 — the local pre-commit hook validates staged blobs

**Step:** `core/09-pre-commit-hooks`.
**Type:** GAP.

**Situation:** the plan's lint example runs `php -l "$file"` against the working-tree path. A commit hook should validate what is actually staged; a file can differ between the index and the worktree, and filenames can contain spaces or newlines.

**Options considered:** (a) run tools against working-tree paths as shown; (b) read each staged blob from the Git index and pipe it to the checker; (c) block commits whenever index and worktree differ.

**Decision:** (b). Enumerate paths NUL-delimited, then feed `git show :<path>` into PHP lint and the debug scan. Exclude deleted entries and skip binary blobs for text scanning. Keep the hook local at `.git/hooks/pre-commit`, as requested, without changing `core.hooksPath` or adding an installer.

**Basis:** 09 A9 tier 2 (no rule covers staged-vs-working-tree validation); judgment: inspecting the committed snapshot is the smallest correct pre-commit check and preserves support for unusual filenames.

**Impact:** `.git/hooks/pre-commit` and this step's report/log. The hook is checkout-local and is not distributed through Git.

**Proposed AGENTS.md change:** none.

**Status:** OPEN — a repository-managed installation mechanism is a separate scope decision.

---

## D-2026-11-15-20 — persona mandates are bounded by project rules and hard stops

**Step:** `core/08-specialized-agent-personas`.
**Type:** RULE CONFLICT (plan instructions vs. binding architecture and hard-stop rules).

**Situation:** the plan says Domain changes may be handled by "Command Handlers", grants the Security Auditor "absolute veto power" over other concerns, and describes exception wrapping generically. This project defines Command/Query Services (AGENTS.md §8), makes security vetoes subordinate to hard stops (AGENTS.md §6 and rule 09 A10), and names the `$wpdb` duplicate translator specifically (rule 02 §7). Literal persona text could therefore teach a nonexistent service shape, authorize bypassing a hard stop, or conflict with the adapter's real exception boundary.

**Options considered:** (a) copy the brief literally; (b) implement persona guidance constrained by the existing project rules; (c) omit persona-specific conflict and veto guidance.

**Decision:** (b). Domain guidance references Entity behavior and the existing Command/Query Service pattern, not Command Handlers. The Security Auditor may veto only a concrete, evidence-backed vulnerability; its veto blocks unsafe completion but cannot override scope, forbidden-action, or other A10 hard stops. DB guidance uses the established `$wpdb` preparation, transaction, and adapter exception-translation rules. The plan's requested security/correctness/purity/performance ordering and core principle are retained.

**Basis:** AGENTS.md §§6, 8, 10–11; rules 02 §7, 05 R1/R4, and 09 A10; A9 precedence 2 over the plan's unqualified wording.

**Impact:** `.claude/rules/12-persona-domain-specialist.md`, `.claude/rules/13-persona-db-optimizer.md`, `.claude/rules/14-persona-security-auditor.md`, `AGENTS.md`, and this report/log. Reversible documentation changes; plan remains read-only.

**Proposed AGENTS.md change:** none — A17 states the project-bounded handover, conflict, and veto protocol.

**Status:** OPEN — developer review of the A17 wording is welcome.

---

## D-2026-11-15-22 — explicit authorization for the Shared read base

**Step:** `Shared/01` (`docs/ai-reports/10-shared-read-repository-helpers.md`).
**Type:** RULE CONFLICT (prior H6 boundary vs. developer authorization).

**Situation:** The brief requires adding read helpers to the existing `BaseReadRepository`, while 09 A4/H6 ordinarily halts changes to an existing Shared contract/base. The developer selected option 2 in chat: directly authorize editing the existing base and the relevant rule/plan scope.

**Decision:** Apply the authorization narrowly to `core/Shared/Infrastructure/Repositories/BaseReadRepository.php`, with explicitly named supporting rule/plan documentation. No other existing Shared artifact is authorized. `09` A4 now records this exact-artifact + SCOPE FILES exception; the plan records the developer's authorization.

**Basis:** Developer's explicit instruction in this task; 09 A9 precedence 1. All other AGENTS.md §6 scope boundaries remain in effect.

**Impact:** `BaseReadRepository.php`, rule files `02`, `03`, `09`, skills `module-scaffolder` and `delete-action-builder`, and `docs/ai-plans/action_plan.md`; reversible.

**Proposed AGENTS.md change:** none — the narrow exception is recorded in execution rule 09 and this decision.

**Status:** OPEN — authorization is task-specific.

---

## D-2026-11-15-23 — the audit found dead code but the brief scopes only reporting

**Step:** `Company/02` (`docs/ai-reports/12-company-module-audit-report.md`).
**Type:** GAP (report-only scope vs. actionable findings).

**Situation:** The audit found a confirmed defect that a scoped implementation step could fix in one edit: `CompanyController::show()` is routed-nowhere dead code that nonetheless returns a stub `['id' => $id]` array to a view expecting five full fields, and `views/company/show.php` reads that array with `$company['name']` while the service that would supply the data returns a `CompanyDetailResponseDTO` object. Phase 4 of the brief asks for "a final checklist of remaining tasks", i.e. findings, not repairs. Fixing the view or deleting the method unprompted would be the §6 SCOPE expansion the brief does not authorize.

**Options considered:** (a) fix the dead `show()` method and its view in this step; (b) delete `show()` and `show.php` as unreferenced; (c) report them and stop, per the brief's Phase 4.

**Decision:** (c). Every finding is recorded with its file, line, rule and smallest in-scope correction, so a follow-up brief can apply them one at a time. Two findings the audit could not settle as defects are reported as such rather than as violations: the bulk-delete loop is *not* a §10 breach, because each `deleteCompany()` call already runs inside `executeInTransaction()` — only cross-row atomicity is absent, which the brief never asked for.

**Basis:** §6 SCOPE and 09 A2.1 (CURRENT STEP ONLY); the brief's Phase 4 is "Gap Report Generation" and names the output file, not a repair.

**Impact:** no source file changed by this step; the report and the two trail files only. Fully reversible.

**Proposed AGENTS.md change:** none.

**Status:** OPEN — the repairs belong in a follow-up brief naming the controller and the view.

---

## D-2026-11-15-24 — the brief's `companies/{id}` cannot address a wp-admin screen

**Step:** `Company/03` (`docs/ai-reports/13-company-details-wiring.md`).
**Type:** RULE CONFLICT (brief acceptance wording vs. verified framework behaviour).

**Situation:** Acceptance criterion 1 requires `routes/web.php` to register "a GET route for `companies/{id}`". Two verified facts make that unbuildable as written. A bare `Route::get()` in `web.php` is stamped `SURFACE_ADMIN_PAGE` (`framework/Routing/WebRouteRegistrar.php:57-60`), and an admin page is resolved by WordPress matching `?page=` against a slug handed to `admin_menu` — a slug is one string with no segments. The module's own `edit` route already encodes this: it is registered as `company-edit` with the id on the query string, and its docblock at `routes/web.php:69-72` says the hidden option exists precisely because the screen is reached by an id on the query string. Separately, `CompanyController::SCREEN_SLUG` is already `companies`, so reusing that slug for show would have collided with the index screen.

**Options considered:** (a) register `companies/{id}` literally and accept a screen that can never be reached — the F-001 failure this step exists to fix; (b) register `company-show` with the id as a query parameter, matching `edit`/`destroy`; (c) switch the screen to the front-end `page()` surface, where placeholders do work, changing the surface the module uses.

**Decision:** (b). The route is `company-show`, named `companies.show`, `hidden: true`, inside the existing `auth` + `capability:manage_options` group. The brief's intent — wire the details action so it is reachable — is met; its literal path is not, because a literal reading produces a dead route.

**Basis:** 09 A9 precedence 3 (specific rule files over a plan's acceptance wording) with the RULE CONFLICT recorded; 02 §6 and the verified `WebRouteRegistrar` source. An acceptance criterion that cannot be satisfied by correct code is the same defect class already recorded twice as D-2026-11-15-09 and D-2026-11-15-19.

**Impact:** `routes/web.php` only; fully reversible, and the slug is one constant if the developer prefers a different name.

**Proposed AGENTS.md change:** none — the plan template should stop expressing a WordPress admin route as a Laravel-style path with a placeholder.

**Status:** OPEN — confirm the slug `company-show`.

---

## D-2026-11-15-25 — `show()` reads the id from the request, not a typed route parameter

**Step:** `Company/03`.
**Type:** GAP (brief signature vs. the addressing mechanism).

**Situation:** Acceptance criterion 2 requires `CompanyController::show(int $id)`. That signature presumes a framework router injects a path placeholder into the argument, which is how the Laravel build works. In this plugin the id reaches an admin handler as request input: `edit()` and `destroy()` both take `Request $request` and read the id through the private `submittedId()` helper. Passing `int $id` would leave the method with no defined source for its argument once the route carries the id as a query parameter.

**Options considered:** (a) keep `show(int $id)` and let the router bind it; (b) take `Request $request` and reuse `submittedId()`; (c) read `$_GET` directly in the method.

**Decision:** (b). One addressing helper serves three handlers, so the three cannot drift, and the null-id guard is identical to `edit()`'s. Recorded as a GAP rather than a conflict: the brief's signature is not wrong in the abstract, only unavailable in this environment.

**Basis:** 09 A9 precedence 5 (existing codebase convention, verified by reading `CompanyController::edit()` and `submittedId()`); A9 Tier 2.

**Impact:** `CompanyController.php` only; reversible.

**Proposed AGENTS.md change:** none.

**Status:** OPEN.

---

## D-2026-11-15-26 — mid-step deletion of ten tracked plan files by an external process

**Step:** `Company/03`.
**Type:** RULE ERROR (environment).

**Situation:** During the C2 scope audit, `git status --porcelain` showed ten unstaged deletions (` D`) of tracked files under `docs/ai-plans/` — files this session listed present, with sizes and timestamps, at its start. No command run in this session deletes files: the session's commands were read-only inspection (`git check-ignore`, `git ls-files`, `ls`, `find`, `grep`, `php -l`, `git status`, `git diff --check`) plus four `Write` and four `Edit` calls confined to the step's SCOPE FILES, the brief and the reports. The likely cause is an automated sync or cleanup process outside this session.

**Options considered:** (a) restore from Git and continue; (b) halt and leave the tree for the developer to inspect; (c) restore and additionally record a deviation.

**Decision:** Raised as a Structured HALT under AGENTS.md §6 ("destructive action") and 09 A10 H3/H9, because the premise that the working tree was intact had failed and earlier steps' inputs might not be intact. The developer confirmed the cause was external, not a destructive action on the agent's part, and explicitly authorized `git restore docs/ai-plans/*.md`. Ten files were restored; `git status` then showed zero deletions and no code file was affected. The incident is recorded here as (c) as well, so a future session knows the tree was disturbed.

**Basis:** AGENTS.md §6 (HALT for a destructive action) and 09 A10 H3/H9; the recovery was performed only on the developer's explicit authorization, per A9 precedence 1.

**Impact:** no source file changed; ten documentation files restored to their committed state. Fully reversible.

**Proposed AGENTS.md change:** none — the cause is environmental, not a rule defect. If recurrences continue, 09 A8 (ENVIRONMENT) may deserve a clause about external processes mutating the tree mid-step.

**Status:** OPEN — monitor for recurrence.

---

## D-2026-11-16-01 — `route()` silently drops parameters for an admin-page surface

**Step:** `Company/04` (`docs/ai-reports/14-company-list-url-refactor.md`).
**Type:** RULE CONFLICT (brief acceptance wording vs. verified framework behaviour).

**Situation:** Acceptance criterion 2 required edit links to use "`route('companies.edit', ['id' => $company->id])`". Read against the generator's source, that call cannot carry the id. `UrlGenerator::compile()` (`framework/Routing/UrlGenerator.php:159-165`) dispatches on the route's surface, and for `SURFACE_ADMIN_PAGE` it calls `compileAdminPage()` (`:210-215`), which runs `substitute()` and nothing else:

```php
$slug = $this->substitute($route, $parameters);
return $this->url('admin_url', 'admin.php?page=' . rawurlencode($slug));
```

`company-edit` has no path placeholders, so `substitute()` returns the slug unchanged and every key in `$parameters` is discarded. `queryString()` — the method that appends unused parameters — is reached only from `compileFrontEndPage()` (`:274`). The caller therefore receives `admin.php?page=company-edit` with the id gone and **no exception raised**. Corroboration: `grep` for `route('<name>', [` across all of `views/` and `app/` returns zero hits — no surface in this project uses that form.

**Options considered:** (a) implement the criterion literally and ship a broken edit link; (b) move the edit screen to a path-placeholder route so `substitute()` has something to replace — rejected because `company-edit/{id}` is a slug with a segment, which D-2026-11-15-24 established WordPress cannot match; (c) build the address from `route(...)` plus the id in the controller and pass a callable to the view.

**Decision:** Raised as a Structured HALT (A10 H7) before any file was edited. The developer selected Option A/(c) and explicitly amended criterion 2. The address is composed by a new private `CompanyController::recordUrl()`, which chooses the separator from the base and appends `http_build_query(['id' => …])`, and is handed to the view as the `editUrlFor` callable alongside a matching `showUrlFor`.

**Basis:** 09 A9 precedence 1 (the developer's explicit instruction amending the brief) over the criterion's literal wording, with the RULE CONFLICT recorded; the amendment is grounded in verified source rather than preference.

**Impact:** `CompanyController.php` and `views/company/index.php`; the composition logic moved from the template into the controller, so the view composes no URL. Fully reversible.

**Proposed AGENTS.md change:** none for the rules — but a plan template should not express a WordPress admin route as `route('<name>', ['id' => …])`. A one-line warning under 02 §6 would save the next author the discovery. Suggested wording: *"`route('<name>', $params)` carries parameters only for a front-end `page()` or REST route; for an admin-page route the generator drops them silently, so a record id belongs on the query string composed by the caller."*

**Status:** OPEN — confirm the proposed `02` §6 note.

---

## D-2026-11-16-02 — `editScreenUrl()` was collapsed into the shared `recordUrl()`

**Step:** `Company/04`.
**Type:** GAP (duplicate composition with one owner).

**Situation:** Before this step the same "append the record id to a screen address" logic existed twice: as the `$editUrlFor` closure inside `views/company/index.php:96-106` and as `CompanyController::editScreenUrl()` at `:397-403`. The two were free to drift, and only the controller's copy could be reused by a second screen.

**Options considered:** (a) keep `editScreenUrl()` beside the new helper so both exist; (b) replace `editScreenUrl()` with the shared `recordUrl()` and update its single caller in `edit()`; (c) leave the controller's method alone and add a second helper for `show`.

**Decision:** (b). One private helper now serves three call sites — `edit()`, `editUrlFor()` and `showUrlFor()` — so the composition has one owner. The one caller of the removed method was updated in the same step.

**Basis:** 01 P5 (one reason to change per class) and P3 (simplest thing that satisfies the boundaries); no rule names this method, so A9 Tier 2 judgement.

**Impact:** `CompanyController.php` only; no behaviour change — the composition output is identical, verified by executing the helper directly. Reversible.

**Proposed AGENTS.md change:** none.

**Status:** OPEN.

---

## D-2026-11-16-03 — the bulk delete loops the single-record port method

**Step:** `Company/05` (`docs/ai-reports/15-company-atomic-bulk-delete.md`).
**Type:** GAP (where a batch's cardinality belongs).

**Situation:** `deleteMultiple()` has to delete N rows atomically. Two shapes were available: (a) loop `CompanyRepositoryContract::delete(int)` inside the Service's one transaction, or (b) add a bulk method to the write port issuing a single `DELETE ... WHERE id IN (...)`.

**Options considered:** (a) loop the existing single-record method inside one `executeInTransaction()` closure; (b) widen the write port with a `deleteMany(array $ids)` and build the `IN (...)` clause in the adapter; (c) add a separate narrow port for bulk deletes.

**Decision:** (a). 05 R8 states the write port exchanges single records ("Write methods and single-record loads take/return ENTITIES"), and the port is deliberately narrow (ISP). A bulk method added for this one caller would carry a use case's cardinality into the persistence contract, and (c) would add an interface with a single implementation for a single call site. The loop lives in the Service, inside the transaction, which is where the cardinality decision belongs. Atomicity is unaffected: `WpTransactionManager` is a singleton whose depth counter makes the outer `run()` the only frame that issues BEGIN/COMMIT, and the repository's `delete()` opens no frame of its own.

**Basis:** 05 R8 and 01 P5 (ports are small and use-case-shaped); 09 A9 Tier 2 — no rule names the batch shape, and the narrow-port reading is the one the existing contract already follows.

**Impact:** `CompanyCommandService.php` only. The N-round-trip cost is real but bounded by the selection size, and was accepted over widening the write port. Reversible: a bulk port method can be added later without touching the Service's contract.

**Proposed AGENTS.md change:** none — 05 R8 already settles it. If bulk operations become common, a rule naming the accepted pattern (loop in the Service vs. a bulk port method) would remove the judgement call.

**Status:** OPEN — worth a note in 05 if a second module needs a batch.

---

## D-2026-11-16-04 — the REST query vocabulary is translated in the controller, not the mapper

**Step:** `Company/06` (`docs/ai-reports/16-company-rest-api.md`).
**Type:** GAP (where a channel's query vocabulary belongs).

**Situation:** `CompanyMapper::mapToFilterDTO()` reads the page number from `paged`, which is WordPress's key for an admin list table and the right name on a wp-admin screen. A REST client sends `page`. Executed against the real mapper, `mapToFilterDTO(['page' => 3])` returned `page=1`: the parameter was ignored and the endpoint answered 200 with the wrong rows — a wrong answer rather than an error, which is the failure mode nobody reports.

**Options considered:** (a) widen `mapToFilterDTO()` to read `page` as well; (b) translate the key in the API controller before the mapper sees it; (c) accept only `paged` on the API and document it.

**Decision:** (b). `CompanyMapper` is Core Application code shared by every channel, and `paged` is the name the web surface already relies on; teaching it a second channel's spelling would put a delivery concern inside a mapper that three surfaces call. The translation therefore happens in `CompanyController::listCriteria()`, at the boundary that owns the query vocabulary, and `paged` still wins when both are sent. (c) was rejected because it makes a conventional spelling silently wrong for every client.

**Basis:** AGENTS.md §2 (delivery owns its channel's input shape) and 06 T7 (delivery: validate → mapper → one service method); 04 STEP 5 keeps the mapper's contract unchanged. A9 Tier 2 — no rule names the query key.

**Impact:** `app/Http/Controllers/Api/CompanyController.php` only. Reversible; the mapper and its contract are untouched.

**Proposed AGENTS.md change:** none for the rules. A plan or rule template would benefit from one line: *"a delivery layer that reads a query string names its own parameters and translates them for the Mapper; do not widen a Core mapper to accept one channel's spelling."*

**Status:** OPEN.

---

## D-2026-11-16-05 — the `PaginatedResult` acceptance item was already satisfied

**Step:** `Company/06`.
**Type:** AMBIGUOUS BRIEF (an instruction to introduce something that already exists).

**Situation:** Phase 3.1 requires the `index` method to "use a framework-agnostic `PaginatedResult<T>` class" instead of Eloquent's `LengthAwarePaginator`. Both already hold: `Core\Shared\Domain\DataObjects\PaginatedResult` exists and `CompanyQueryService::getPaginatedCompanies()` already returns it inside a `Result`. `grep -rn "LengthAwarePaginator"` across the project returns zero hits — no paginator was ever in use, so there was nothing to replace.

**Options considered:** (a) treat the item as already met and say so; (b) create a second `PaginatedResult` under `app/` to "satisfy" the brief; (c) add a wrapper type around the existing one.

**Decision:** (a). (b) and (c) would each create a duplicate of a Shared kernel type — the AGENTS.md §6 FILE EXISTENCE GUARD failure class — to satisfy an instruction whose intent is already in force. The controller reads `$page->items()` and `$page->meta()`, which is the class the phase asks for.

**Basis:** AGENTS.md §6 (a second file at an occupied path is the worst scope violation) and §3 (Shared Application is FROZEN and ports unchanged). A9 Tier 2.

**Impact:** no file created; the report records the item as met with evidence.

**Proposed AGENTS.md change:** none.

**Status:** OPEN — the brief's wording implies the class was missing; it was not.

---

## D-2026-11-16-06 — the API capability is read from the admin controller

**Step:** `Company/06`.
**Type:** GAP (one policy, two surfaces).

**Situation:** The admin screens gate on `CompanyController::CAPABILITY` (`'manage_options'`). The REST surface needs the same authorization, and the obvious spelling is a second literal in the new middleware.

**Options considered:** (a) restate `'manage_options'` in `RequireCompanyManage`; (b) read the constant from the admin controller; (c) move the constant to a new shared location both surfaces import.

**Decision:** (b). The API endpoint edits the same records the screens edit, so the two must admit the same population; reading the one constant means they cannot drift. (a) permits a future edit to one string and not the other — after which a user who can change a company in wp-admin is refused by the endpoint that changes it, which is a bug nobody looks for. (c) would add a type whose only content is one string, and would leave the admin controller's existing public constant dangling.

**Basis:** 01 P5 (one reason to change) and the "narrowest capability covering the operation" reasoning already recorded in `RequireLeadRead`. A9 Tier 2 — no rule names where a shared capability constant lives.

**Impact:** `app/Http/Middleware/RequireCompanyManage.php` importing `App\Http\Controllers\Admin\CompanyController`. Both are `App\` delivery classes, so the import crosses no zone boundary. Reversible.

**Proposed AGENTS.md change:** none.

**Status:** OPEN.

---

## D-2026-11-16-07 — `Company/07` closes as already-satisfied; "(with filters)" is inapplicable

**Step:** `Company/07` (`docs/ai-reports/17-company-update-list-verification.md`).
**Type:** RULE CONFLICT (a brief whose SCOPE FILES cannot reach its own GOAL).

**Situation:** The step's GOAL is "Implement Update and Paginated List (with filters) endpoints", and its ACCEPTANCE asks `index()` to build a `CompanyFilterDTO` from request parameters. Both endpoints already existed from `Company/06`, so acceptance items 1, 2 and 4 were met on arrival and item 3 was met in substance. The "filters" half was not: `CompanyFilterDTO` and `CompanyFilter` carry only `perPage` and `page`. A sweep of every other module showed `Company` is the only module whose filter has no criteria — `Lead` carries `status`/`source`/`search`, `Contact` `search`, `Deal` `pipelineId`/`dealStageId`, `Activity` six fields. Building one would need `CompanyFilter` and `CompanyFilterDTO` (both FROZEN, AGENTS.md §3) plus `CompanyMapper` and `CompanyQueryContract`/`WpCompanyReadRepository` — four artifacts the brief's SCOPE FILES did not name, and three modes in one step.

**Options considered:** (a) close the step as already-satisfied and treat "(with filters)" as inapplicable; (b) build real filters, expanding scope to the FROZEN Domain/Application artifacts and the write-adjacent read port; (c) implement filtering in the controller by post-filtering a page in delivery.

**Decision:** (a), on the developer's explicit instruction after the conflict was raised as a Structured HALT. (b) was the honest reading of the GOAL but exceeded the authorized scope and would have edited FROZEN files without authorization; (c) was rejected on principle before the developer chose, because post-filtering a returned page yields the wrong rows and a `total` that contradicts them while putting a query concern in delivery. The developer authorized closing the step, directed that no filter code be written and no FROZEN file touched, and confirmed `getPaginatedCompanies()` as the fulfilled pagination requirement.

**Basis:** `09 A10` (halt rather than expand scope) and `09 A4` (changing an EXISTING FROZEN or Shared artifact requires the developer to authorize that exact artifact and the brief to list it in SCOPE FILES); AGENTS.md §3 (FROZEN folders) and §6 (SCOPE). A9 precedence 1 — the developer's explicit instruction resolves the conflict.

**Impact:** no source file created, edited or deleted. `routes/api.php`, `app/Http/Controllers/Api/CompanyController.php` and both `CompanyQueryService*` files are byte-identical to their `Company/06` state. `git status --porcelain` is unchanged from the previous step apart from the two trail files and this report. Fully reversible.

**Proposed AGENTS.md change:** none for the rules — they already forbid this scope expansion. A brief-authoring note would help: *"a step brief whose GOAL names a capability must list in SCOPE FILES every artifact that capability requires; a filter expressed in Domain/Application is not reachable from a delivery-only scope."*

**Status:** OPEN — the developer accepted the module shipping without list filters.

---

## D-2026-11-16-08 — the REST `{}` defect is fixed in the framework, not in the Company controller

**Step:** `Company/08` (`docs/ai-reports/18-company-rest-empty-body-diagnosis.md`).
**Type:** GAP (which layer owns terminating a REST response).

**Situation:** `GET /wp-json/wp-mini-laravel/v1/companies` answers **200** with body `{}` while `leadbridge_companies` holds rows. The cause is not in the module: `CompanyController` returns a `Framework\Http\Responses\JsonResponse`, the REST publication path never calls `send()` on it and never unwraps it (`Router::dispatchTo()` at `framework/Routing/Router.php:398` and `RestApiRouterAdapter::destination()` at `framework/Routing/Adapters/RestApiRouterAdapter.php:247` both hand the object straight back), so WordPress's `rest_ensure_response()` wraps the *object* in `new WP_REST_Response($object)`, `get_data()` returns it, and `wp_json_encode()` emits `{}` because `JsonResponse` has only private properties. The web adapter *does* own sending (`WordPressRouterAdapter.php:646,678,809,868`), so the two domains disagree about who terminates the request. `LeadController` — the pattern `CompanyController` followed — is broken identically and was never caught because its `index()` returns hardcoded sample rows.

**Options considered:** (a) fix in `framework/` with a REST normalizer (adapter-local at dispatch, or centrally on `rest_request_after_callbacks`) so a callback's `ResponseInterface` becomes a `WP_REST_Response` before WordPress serializes it; (b) change the delivery convention so REST controllers return plain arrays / `JsonResource` and never a `JsonResponse`, updating `LeadController`, `CompanyController` and the `make:controller` REST stub; (c) leave it and document it.

**Decision:** **not yet taken — this is a Structured HALT.** (a) requires a `framework/` edit, which AGENTS.md §2 forbids for a module task and §6 makes a hard stop; (b) needs no framework edit but changes the REST contract across every controller and abandons `ResponseInterface` on REST; (c) is unacceptable because it leaves a silent `{}` under a success status. The three options and their trade-offs were reported, and the developer's choice is what this decision is waiting on. No file was edited in this step.

**Basis:** AGENTS.md §2 ("Needed framework feature missing: HALT… A module task NEVER edits `framework/` or `vendor/`"), §6 (HALT for a needed change in `framework/`), and `09 A10` (Structured HALT with 2–3 options rather than an unauthorized fix). A9 precedence 1 — the developer's explicit instruction is required to resolve it.

**Impact:** none to source. This report was the only file created; `git status` is otherwise unchanged by this step. Fully reversible.

**Proposed AGENTS.md change:** none for the rules — they already forbid this fix. Two adjacent notes would help: *"a framework response type is only ever sent by the adapter that owns its domain; a controller returning `ResponseInterface` on the REST domain is a framework defect, not a delivery mistake"*, and the generator side — *"a `make:controller` REST stub must not emit a return type the REST adapter cannot render."*

**Status:** OPEN — awaits the developer's choice between (a), (b) and (c).

---

## D-2026-11-16-09 — the REST adapter unwraps a `ResponseInterface`; `data()` joins the contract

**Step:** `Framework/01` (authorized JOB F, resolving `D-2026-11-16-08`).
**Type:** GAP (which layer unwraps a response type, and whether the body is readable).

**Situation:** `D-2026-11-08`'s `{}`-under-200 defect had to be fixed in `framework/`. The controller returns a `ResponseInterface`; the web adapter *sends* one (`WordPressRouterAdapter`) while the REST adapter returned it straight to WordPress, which wrapped the object in `new WP_REST_Response($object)` and JSON-encoded `get_data()`. The contract declared only `send()` and `status()`, so nothing could read a body generically — the adapter had no way to unwrap even if it tried.

**Options considered:** (a) unwrap in the REST adapter in `destination()`, and add `data(): mixed` to `ResponseInterface` so the body is readable (the developer's choice); (b) unwrap centrally on WordPress's `rest_request_after_callbacks` filter; (c) read the body through `method_exists` and leave the contract at two methods.

**Decision:** (a). The adapter is the boundary that owns its domain's rendering, and a global filter would have to reconstruct the status/header mapping for every REST route in the process, including other plugins'. (c) was rejected because it weakens the guarantee for the exact operation the method exists to perform — the contract would still not promise a readable body, so the next adapter would repeat the guess. Adding `data(): mixed` is a **breaking change** to a documented public interface (F4), taken deliberately and recorded here: `HtmlResponse`, `LayoutResponse` and `RedirectResponse` gained it (returning markup, the child's data, and the redirect target respectively); `JsonResponse` and `ViewResponse` already had it. All five verified.

**Basis:** AGENTS.md §2 (FRAMEWORK mode authorized by the developer for this step) and 10 F1/F4 (adapters translate; a contract change is SemVer-relevant and recorded). The alternative was left documented. Non-`ResponseInterface` returns are passed through untouched, so arrays, `WP_REST_Response` and this class's own `WP_Error` failure paths are unaffected — re-wrapping them would have changed working endpoints.

**Impact:** `framework/Http/Contracts/ResponseInterface.php`, `framework/Http/Responses/{Html,Layout,Redirect}Response.php`, `framework/Routing/Adapters/RestApiRouterAdapter.php` (new private `toRestResponse()` / `responseHeaders()`), and `README.md`. `app/` and `core/` were **not** touched — no controller edit was needed, since returning `JsonResponse` is now correct. Proven by execution: against the real `JsonResponse` class the body goes from `{}` to the full payload. Reversible.

**Proposed AGENTS.md change:** none. §2 and F1 already place this. Worth a line in 10 F2's differences list: *"a REST controller may return a `ResponseInterface`; the REST adapter unwraps it into a `WP_REST_Response` rather than requiring `WP_REST_Response` directly."*

**Status:** OPEN — the `data()` contract addition is a breaking change for any third-party implementer, and is recorded for the changelog.

---

## D-2026-11-16-10 — the REST unwrap is ported to `Router`, the router that actually publishes

**Step:** `Framework/02` (`docs/ai-reports/19-rest-response-unwrap-live-path.md`).
**Type:** GAP (a fix applied to a sibling of the live object).

**Situation:** After `Framework/01`, `GET /wp-json/wp-mini-laravel/v1/companies` still answered **200** with `{}`. `Framework/01` added `toRestResponse()` to `RestApiRouterAdapter`, which is bound (`app/Providers/RouteServiceProvider.php:55`) but never fed routes and never asked to publish: the live REST path is `framework/Routing/RouteServiceProvider.php:107-124`, which resolves **`Router`**, loads `routes/api.php` into it, and calls `registerRoutes()`. `Router::dispatchTo()` hands the controller's raw return value to WordPress (`framework/Routing/Router.php:398`), so `rest_ensure_response()` wrapped the response *object* and `wp_json_encode()` emitted `{}`. `RestApiRouterAdapter::flush()` has zero callers; `'router.api'` is read only by `route:list`. `D-2026-11-16-09`'s "proven by execution" was true of the adapter in isolation — correct code on a path no request travels.

**Options considered:** (1) port the same unwrap into `Router`, the object actually publishing; (2) route REST through `RestApiRouterAdapter` and retire `Router`'s REST role; (3) unwrap centrally on `rest_post_dispatch`.

**Decision:** (1), on the developer's explicit choice. Smallest correct fix, contained in `Router.php`, fixes every REST endpoint at once. (2) is the right long-term shape — it removes the duplicate implementation this defect grew from — but it is a migration across `route:list`, `RouteLoader` and `NamedRoutes`, not a bug fix; recorded as the recommended follow-up step. (3) would re-derive status/header mapping for every REST route in the process, including other plugins'. `toRestResponse()` and `responseHeaders()` on `Router` mirror the adapter's verbatim so the two cannot drift while both exist. `dispatch()` wraps its result **outside** the `withHttpRequest()` closure, leaving the request-scoped binding untouched; `dispatch()` has one caller, the REST callback, so web routing is unreachable from the change.

**Basis:** AGENTS.md §2 (FRAMEWORK mode, authorized by the developer for this step) and §6; 10 F1 (adapters translate). A9 precedence 1 — the developer's explicit instruction resolved the same halt raised in `D-2026-11-16-08`.

**Impact:** `framework/Routing/Router.php` only (imports, `dispatch()`, two new private methods, corrected class docblock). `core/`, `app/`, `routes/` and the adapter were **not** touched. Verified by execution against the real edited class: the list payload goes from `{}` to the full body, with 201/`Location`, 204/null and pass-through of non-`ResponseInterface` values all confirmed. `php -l` clean. Reversible.

**Not verified:** no live WordPress request was run. `LeadController::index()` still returns hardcoded sample rows — a separate defect, out of scope.

**Proposed AGENTS.md change:** none for the rules. A step-authoring note would help: *"when a fix is authorized in `framework/`, the brief must name the object the runtime actually resolves — a defect proven against a class is not fixed until the publishing path is identified, and a sibling implementation of the same behaviour is the trap."*

**Status:** OPEN — the duplicate `toRestResponse()` in two routers is deliberate and temporary; collapsing it (option 2) is recommended as its own step.

---

## D-2026-11-16-11 — the unported modules must be ported or removed; the half-ported state is not a third option

**Step:** `Review/01` (`docs/ai-reports/20-full-project-review.md`).
**Type:** GAP (a port that was started module by module and never finished, over a shared base that has since moved).

**Situation:** Only `Company` is ported, wired and reachable. `Lead` is `$wpdb`-backed but unwired and off the shared bases. `Activity`, `Auth`, `Contact`, `Dashboard`, `Deal`, `Pipeline` still hold Eloquent adapters in `core/*/Infrastructure` (20 files, 16 unresolvable FQCNs), and two of them are fatal at class load: `ActivityWriteRepository::write()` and `DealWriteRepository::write()` are `private` against `BaseWriteRepository::write()`'s `protected`. Six required shared ports have no WordPress adapter and `ClockContract` does not exist. This review is read-only and does not choose between the remedies.

**Options considered:** (a) port the seven modules one at a time on the `Company` template — highest value, seven authorized steps, the port audit can then run on the whole tree; (b) delete the unported modules from the tree until they are ported — removes the fatals and the false impression of a working feature set, loses no working behaviour (none of it can run), and makes the port a fresh start rather than a repair; (c) leave them and only stop the fatals — cheapest, keeps `Core\User` and `App\Models` references alive and guarantees the next base-method change breaks them again.

**Decision:** **not yet taken — this is a Structured HALT.** Every option needs the developer's authorization: (a) and (b) delete or rewrite files that `docs/ai-plans/` and earlier reports already treat as delivered; (c) is a known-inadequate stopgap. The choice also determines whether `Core\User`, which five modules reference and which does not exist, is created or the references are dropped.

**Basis:** AGENTS.md §3 (FROZEN folders port unchanged; a module is ported or it is not), §6 (HALT rather than expand/delete out of scope), §14 (checklist assumes the port audit ran); 06 T8/T9. A9 precedence 1 — the developer's instruction resolves it.

**Impact:** none. This review edited no source, created only its report and this entry, and appended one `PROJECT-LOG.md` row. Reversible.

**Proposed AGENTS.md change:** none for the rules. Two step-authoring notes are recorded in the report §9 (base-change class-load sweep; autoload scan in every porting acceptance).

**Status:** OPEN — awaits the choice between porting (a), pruning (b) and the stopgap (c), and a decision on `Core\User`.
