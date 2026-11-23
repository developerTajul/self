# Step 04 — AGENTS.md and Execution-Rule Upgrade (`core/04-agents-and-rules-upgrade`)

**Status:** DONE_WITH_NOTES
**Date:** 2026-11-15
**Branch:** `wpdb`
**Plan:** `docs/ai-plans/action_plan_upgrade_agents_md_and_rules.md`
**Previous steps:** `01-repository-split-refactor.md`, `02-repository-rules-cleanup.md`, `03-skill-files-cleanup.md`

## Summary

Added the two startup gates the plan asked for (Startup & Pre-Flight Protocol, Rule-to-Skill
Sync), the 3-part Structured HALT template, and the `.gitignore` fix that makes the rules,
skills and decision trail survive a fresh clone. The `.gitignore` work was larger than the plan
anticipated: `.claude/` was ignored **and** the broader `/docs` entry was ignoring
`docs/ai-plans/` and `docs/ai-reports/` as well, so five steps of plans, reports and decisions
were untracked. Both are fixed; 18 `.claude/` files and 11 `docs/` files are now committable.

The step is DONE_WITH_NOTES for three reasons: the plan's literal acceptance command
(`git check-ignore … returns no matches`) cannot be satisfied by any pattern that also ignores a
single local file under `.claude/`, which is what the plan asks for in the same sentence
(D-2026-11-15-09); the Git Tracking Guard had to be written against the **exit code** of that
command rather than its output; and `delete-action-builder` was found to cite the principle
section of rule `02` that does not exist, outside this step's scope (D-2026-11-15-11).

## Inputs used

`docs/ai-plans/action_plan_upgrade_agents_md_and_rules.md`; `AGENTS.md`;
`.claude/rules/09-autonomous_execution.txt`; `.gitignore`; `docs/ai-reports/03-skill-files-cleanup.md`
(whose report predicted both the `.claude/` gap and the five stale skills);
`docs/ai-reports/DECISIONS.md` and `PROJECT-LOG.md` (per the new §0 protocol, which this step
was itself adding).

## Rules and skills applied (PROCESS GUARD, before editing)

- §2 MODE DETECTION — documentation mode. No `core/`, `app/`, `framework/` or `vendor/` path
  touched; this step's own acceptance forbids it. Verified by file list below.
- §6 SCOPE — four artifacts, all named by the plan. No module file opened for edit.
- §6 RULE-TO-SKILL SYNC / 09 A5b — the rule this step added, applied to this step (below).
- 09 A5 S1 — file existence checked before creating the report; no other file created.
- 09 A2.5 — `.claude/rules/09` and `AGENTS.md` are editable **because the brief says so**;
  without that sentence this step would be a scope halt.
- 09 A5b self-trigger — editing `AGENTS.md` and rule `09` obliged the skill sweep; run below.
- Tests: **Paused per AGENTS.md.** No test written, scaffolded or run.

## 1 Git Visibility (`.gitignore`)

Two entries were hiding the project's memory, not one:

| Line | Entry | Effect found |
| --- | --- | --- |
| 10 | `/docs` | ignored `docs/ai-plans/` **and** `docs/ai-reports/` — plans, reports, `DECISIONS.md`, `PROJECT-LOG.md` |
| 34 | `.claude/` | ignored `.claude/rules/` and `.claude/skills/` |

`git ls-files .claude` and `git ls-files docs` both returned **0** files before this step, so
steps `core/01`–`core/03` and their decisions existed only on this machine. `/docs` was removed
outright (nothing else lives under it). `.claude/` was replaced by an ignore-everything shape
plus two negations:

```gitignore
.claude/*
!.claude/rules/
!.claude/skills/
```

Anchoring is deliberate. A bare `rules/` would ignore `rules/` at every depth; the negations are
written to be understood when the file is read top to bottom, because a negation cannot undo an
excluded **directory** — so `.claude/` must never be re-added alongside them.

## 2 Startup & Pre-Flight Protocol (`AGENTS.md` §0)

New section placed before §1, running before mode detection:

- **CONTEXT HYDRATION** — last 3 entries of `DECISIONS.md`, latest `PROJECT-LOG.md` entry, plus
  the previous step's report. An OPEN decision touching the task binds it, or is overridden with
  a recorded reason.
- **GIT TRACKING GUARD** — `git check-ignore -v .claude/rules .claude/skills docs/ai-reports`
  must show none of the three ignored; any of them ignored is a HALT and nothing else proceeds.
- A missing file from either check is a HALT; never create it to pass the check.

Note on the prescribed command and the Structured HALT: see D-2026-11-15-09. The guard rule
names rule `09` A10 as the halt basis and states the check in terms of what the command
**means** (exit code), because the plan's literal "returns no output" wording is unsatisfiable —
see the deviation below.

## 3 Self-Healing Rule-to-Skill Synchronization

Added in two places, worded for the two readers:

- `AGENTS.md` §6 — the binding rule, next to the existing `AUTONOMOUS MODE` bullet.
- `09-autonomous_execution.txt` A5b — the procedure, plus enforcement: self-check **C15**, a new
  §A15 anti-pattern, and the DoD line updated to `C1-C15`.

The load-bearing detail, recorded because the obvious approach fails: **sweep for the OLD
terms**. Stale skill text contains the string that was removed, so grepping for the new rule
file name, the new class name or the new section number returns nothing and reads as a clean
sweep. `AGENTS.md` also reminds that the stale skill is the symptom, not the cause — as in
step `core/03`, where unreadable rule-file citations and `Route::resource()` came from a
generator, so the sweep could not have fixed them.

## 4 Structured HALT Output (`09-autonomous_execution.txt`, A10)

Added `HALT MESSAGE FORMAT (binding)` immediately after the A10 hard-stop list and the "at a
halt" sentence: **1 BROKEN RULE / PREMISE** (exact section + sentence, or the failed premise with
the check that failed), **2 CONFLICTING FILES** (`path:line` with quoted offending text, or the
command output proving the conflict is brief-vs-reality), **3 ARCHITECTURAL OPTIONS** (2–3
options with files changed, trade-off, and the one recommended under A9). A single-option
message is explicitly not a halt. Ordering is fixed: the three parts come before any other text,
then the Interactive Alert (§6), report and PROJECT-LOG.

## STALE_SKILLS_DETECTED

A5b sweep, run because this step edited `AGENTS.md` and `.claude/rules/09-autonomous_execution.txt`.
Swept terms: `02-framework-adapter-laravel.txt`, `09-autonomous-execution.txt`, `Route::resource`,
`DB::`, `BaseRepository` — **7 `SKILL.md` files, 30 hits.** Each row is a skill instructing an
agent to use something this project does not have.

| # | File | Line | Quoted stale text (abridged) | Contradicts | Proposed fix |
| --- | --- | --- | --- | --- | --- |
| 1 | `.claude/skills/delete-action-builder/SKILL.md` | 5 | `02-framework-adapter-laravel.txt` | rule file does not exist | correct the citation (D-2026-11-15-11) |
| 2 | `.claude/skills/delete-action-builder/SKILL.md` | 7 | `09-autonomous-execution.txt` | wrong separator; real file `09-autonomous_execution.txt` | fix the path |
| 3 | `.claude/skills/delete-action-builder/SKILL.md` | 36 | "Routes are declared with `Route::…" | no such router in WordPress | rewrite against `routes/web.php` |
| 4 | `.claude/skills/delete-action-builder/SKILL.md` | 46 | `Route::resource('{module}', …);` | ditto | hand-written route in the existing group |
| 5 | `.claude/skills/delete-action-builder/SKILL.md` | 310 | "[ ] Route is declared through `Route::resource()`" | ditto | checklist item rewritten |
| 6 | `.claude/skills/delete-action-builder/SKILL.md` | 326 | "…inside `DB::…" | Laravel `DB` facade | `TransactionManagerContract::run()` |
| 7 | `.claude/skills/edit-form-builder/SKILL.md` | 10 | `02-framework-adapter-laravel.txt` | rule file does not exist | correct the citation |
| 8 | `.claude/skills/edit-form-builder/SKILL.md` | 12 | `09-autonomous-execution.txt` | wrong separator | fix the path |
| 9 | `.claude/skills/edit-form-builder/SKILL.md` | 39 | "…declared with `Route::resour…" | no such router | rewrite against `routes/web.php` |
| 10 | `.claude/skills/edit-form-builder/SKILL.md` | 49 | `Route::resource('{module}', …);` | ditto | hand-written route |
| 11 | `.claude/skills/edit-form-builder/SKILL.md` | 224 | "[ ] **Route declared through `Route::resource()`**" | ditto | checklist item rewritten |
| 12 | `.claude/skills/form-builder/SKILL.md` | 12 | `02-framework-adapter-laravel.txt` | rule file does not exist | correct the citation |
| 13 | `.claude/skills/form-builder/SKILL.md` | 14 | `09-autonomous-execution.txt` | wrong separator | fix the path |
| 14 | `.claude/skills/form-builder/SKILL.md` | 36 | "Routes are declared with `Route::resource()`" | no such router | rewrite against `routes/web.php` |
| 15 | `.claude/skills/form-builder/SKILL.md` | 42 | `Route::resource(…)->only(['create']);` | ditto | hand-written route |
| 16 | `.claude/skills/form-builder/SKILL.md` | 112 | "[ ] Route uses `Route::resource()->only(…)" | ditto | checklist item rewritten |
| 17 | `.claude/skills/index-action/SKILL.md` | 12 | `02-framework-adapter-laravel.txt` | rule file does not exist | correct the citation |
| 18 | `.claude/skills/index-action/SKILL.md` | 14 | `09-autonomous-execution.txt` | wrong separator | fix the path |
| 19 | `.claude/skills/index-action/SKILL.md` | 127 | "Routes use `Route::resource()` inside…" | no such router | rewrite against `routes/web.php` |
| 20 | `.claude/skills/index-action/SKILL.md` | 132 | `Route::resource(…)->only(['index']);` | ditto | hand-written route |
| 21 | `.claude/skills/index-action/SKILL.md` | 181 | "[ ] Route uses `Route::resource()` inside…" | ditto | checklist item rewritten |
| 22 | `.claude/skills/store-action-builder/SKILL.md` | 12 | `02-framework-adapter-laravel.txt` | rule file does not exist | correct the citation |
| 23 | `.claude/skills/store-action-builder/SKILL.md` | 14 | `09-autonomous-execution.txt` | wrong separator | fix the path |
| 24 | `.claude/skills/store-action-builder/SKILL.md` | 118 | "…inside `TransactionManagerContract::run()` (… `DB::" | Laravel `DB` facade | drop the `DB::` reference |
| 25 | `.claude/skills/store-action-builder/SKILL.md` | 121 | "Routes use `Route::resource()` inside…" | no such router | rewrite against `routes/web.php` |
| 26 | `.claude/skills/store-action-builder/SKILL.md` | 126 | `Route::resource(…)->only(['create', 'store']);` | ditto | hand-written routes |
| 27 | `.claude/skills/store-action-builder/SKILL.md` | 166 | "…with no `DB::" | Laravel `DB` facade | name the transaction contract |
| 28 | `.claude/skills/store-action-builder/SKILL.md` | 171 | "[ ] Route uses `Route::resource()` inside…" | ditto | checklist item rewritten |
| 29 | `.claude/skills/update-action-builder/SKILL.md` | 97 | "[ ] The route is declared in `routes/web.php`…" | cites the real file; residual `Route::resource` context | verify and align wording |
| 30 | `.claude/skills/update-action-builder/SKILL.md` | 112 | "…inside `TransactionManagerContract::run()`.** No manual `beginT…" | correct contract, but the pre-`executeInTransaction()` manual shape | align with AGENTS.md §10 |

**Not edited — outside this step's write scope.** The plan names `AGENTS.md`, rule `09` and
`.gitignore`; `core/03` (D-2026-11-15-08) already recorded these five skills as needing a
follow-up brief that names them. This is the third step in a row to report the same list, now
with line numbers attached.

## Self-check table

| Check | Result | Evidence |
| --- | --- | --- |
| C1 files | PASS | 4 modified (`AGENTS.md`, `.gitignore`, rule `09`, + report/`DECISIONS.md`/`PROJECT-LOG.md`); no file created outside `docs/ai-reports/` |
| C2 scope | PASS | plan names `.gitignore`, `AGENTS.md`, rule `09`; skills deliberately untouched (D-2026-11-15-10) |
| C3 syntax | N/A | documentation step, no `.php` touched. §6 A7 single-file `php -l` not applicable |
| C4 autoload | N/A | no namespace path added |
| C5 DoD | PASS | 09 A16 walked; its `[ ] No rule file, AGENTS.md, skill or plan edited` is **inapplicable** — the brief orders exactly that |
| C6 `core/` greps | N/A | `core/` not touched |
| C7 wiring | N/A | no Contract, route or provider |
| C8 schema | N/A | no schema |
| C9 leftovers | PASS | no `dd`/`dump`/stray TODO added |
| C10 runtime | N/A | no runtime behaviour to exercise |
| C11 tests | PASS | "Paused per AGENTS.md", none claimed |
| C12 transactions | N/A | no write path |
| C13 boundaries | PASS | no `Core\`/`App\`/`Framework\` file touched |
| C14 file existence | PASS | `ls` confirmed `DECISIONS.md`/`PROJECT-LOG.md`/rule `09` exist before editing; report path free (`ls docs/ai-reports/`) |
| C15 rule-to-skill sync | PASS | A5b sweep above: 30 hits recorded, 0 edited |

## Commands run

```
git check-ignore -v .claude .claude/rules .claude/skills docs/ai-reports
git ls-files .claude | wc -l          # 0 before
git ls-files docs | wc -l             # 0 before
git add -An .claude                   # 18 files now addable
git add -An docs                      # 11 files now addable
git check-ignore -q <path>            # exit 0 = ignored, 1 = tracked
grep -rn "\.claude\|ai-reports\|ai-plans" --include=*.php --include=*.xml …   # no hits
```

## Decisions (D-IDs)

`D-2026-11-15-09` Structured HALT section-number coupling and the unsatisfiable acceptance
command. `D-2026-11-15-10` skills reported, not edited. `D-2026-11-15-11` `delete-action-builder`
cites a principle section of rule `02` that does not exist. Full text in `DECISIONS.md`.

## Deviations

1. **Acceptance criterion reinterpreted.** The plan requires
   `git check-ignore .claude/rules .claude/skills` to return "no matches", and in the same
   sentence requires that individual cache/temp files under `.claude/` be ignored. Those two
   cannot both hold: a negation rule must exist for the first, and `git check-ignore` prints
   whichever pattern decides a path — including a negation, as `!.claude/rules/`. The criterion
   was met by its **exit code and behaviour** (`git check-ignore -q` → exit 1 for all three
   paths; 18 files addable) instead of by empty stdout. See D-2026-11-15-09.
2. **`/docs` was removed as well.** The plan only mentions `.claude/`. Leaving `/docs` would
   have defeated §0's own Context Hydration on a fresh clone, since `docs/ai-reports/` is a
   chased dependency (D-2026-11-15-09).
3. **Renumbered the self-check range to C1-C15** and updated the DoD line, rather than adding
   the sync check as an unnumbered bullet. Rule `09` C1-C14 is cited by number elsewhere.
4. **`AGENTS.md` §0 placed before §1**, so it reads before mode detection; the treatment of the
   first heading was left alone deliberately — renaming it to `§0` while `CLAUDE.md` and rule
   files cite `§2`/`§6` risks a second, less visible staleness (D-2026-11-15-09).

## Deferred and open items

- **30 `STALE_SKILLS_DETECTED` hits** across the five skills (D-2026-11-15-10) — unchanged from
  D-2026-11-15-08, now itemised by line.
- **`delete-action-builder` cites a nonexistent principle section of rule `02`**
  (D-2026-11-15-11) — same class of defect as D-2026-11-15-08, not previously itemised.
- **Nothing is committed.** The `.gitignore` fix makes the files *committable*; `git add`/`commit`
  is a forbidden A7 operation, so `core/01`–`core/04` are still uncommitted and still on this
  machine only. This step removes the obstacle; the developer must commit.
- Steps `01`/`02` items unchanged: five dead Eloquent adapters, `findRecordForUpdate()` unverified
  at runtime (`FakeWpdb` cannot parse `FOR UPDATE`).

## Tests

Paused per AGENTS.md. No test written, scaffolded, required or run.

## Next step

Fix the 30 stale-skill references (needs a brief naming the five skills), or resume the
per-module repair of the five dead Eloquent adapters.
