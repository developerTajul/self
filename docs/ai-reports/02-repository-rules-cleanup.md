# Step 02 — Repository Rules Cleanup (`core/02-repository-rules-cleanup`)

**Status:** DONE_WITH_NOTES
**Date:** 2026-11-15
**Branch:** `wpdb`
**Plan:** `docs/ai-plans/prompt-rules-cleanup.md`
**Previous step:** `docs/ai-reports/01-repository-split-refactor.md`

## Summary

Removed the residual `BaseRepository` references from the four rule files that step 01 left
untouched for scope reasons. Eleven of the twelve hits were replaced with the correct member
of the new three-class hierarchy; the twelfth is kept deliberately because it names
**Laravel's** own `BaseRepository` as the left-hand side of a Laravel→WordPress wording map,
which is the "historical context" the brief's acceptance criterion exempts.

The step is DONE_WITH_NOTES because the acceptance criterion ("a global grep returns 0 hits")
is met for the four named rule files but **six further references exist in two skill files**
that the brief did not name. Per A2.5 they were not edited; they are recorded below as the
next follow-up.

## Inputs used

`docs/ai-plans/prompt-rules-cleanup.md`; `docs/ai-reports/01-repository-split-refactor.md`
(D-2026-11-15-04, which enumerated exactly these four files); the four rule files themselves;
`AGENTS.md` §4 D4 as rewritten in step 01.

## Rules and skills applied (PROCESS GUARD, before editing)

- §2 MODE DETECTION — **CORE-PURE** as the brief states, in the documentation sense: this step
  edits rule text only. No `core/`, `app/`, `framework/` or `vendor/` path touched.
- §6 SCOPE — only the four files the brief names were edited; the six skill hits found during
  the acceptance sweep were recorded, not fixed.
- 09 A2.5 — never edit rules or skills the brief did not name; propose in the report.
- 09 A5 S1/S3 — rule sections listed before editing; acceptance re-run after.
- Constraint from the brief — "do not modify any `.php` files, actual code, or adapters":
  **held**, verified by timestamp below.
- Tests: **Paused per AGENTS.md.** No test written, scaffolded or run.

## File-name reconciliation

The brief names three files that do not exist under those names. The real files, confirmed by
listing `.claude/rules/`, and their hit counts matched the brief's estimates exactly:

| Brief name | Real file | Brief estimate | Actual |
| --- | --- | --- | --- |
| `02-framework-adapter-wp.txt` | `02-framework-adapter-wordpress.txt` | ~5 | 5 |
| `05-error-handling.txt` | `05-rich-domain-and-services.txt` | ~2 | 2 |
| `09-autonomous_execution.txt` | `09-autonomous_execution.txt` | C13 | 1 (C13) |
| `11-test-strategy.txt` | `11-fluent-database.txt` | ~3 | 3 |

Recorded as an AMBIGUOUS BRIEF decision (D-2026-11-15-05) rather than a halt: the counts,
the C13 pointer and the subject matter identify each file unambiguously.

## Files modified

All four are **untracked local files** — `.claude/` is in `.gitignore` (line 34), so these
edits are on disk but invisible to `git diff`. Timestamps confirm the writes landed.

| File | Hits fixed | Change |
| --- | --- | --- |
| `.claude/rules/02-framework-adapter-wordpress.txt` | 5 | L27 module tree (write→`BaseWriteRepository`, read→`BaseReadRepository`); L41 shared-adapters table now lists the three abstract bases; L63 SQL-location rule names both base classes; L65 row lock cites `BaseWriteRepository::findRecordForUpdate()`; L77 override-table right-hand side maps Eloquent `BaseRepository` to the three-class split. |
| `.claude/rules/05-rich-domain-and-services.txt` | 2 | L136 two-ports rule (both `extends` clauses + `BaseWriteRepository::findRecordForUpdate()`); L139 anti-pattern reworded to "a read adapter extending a write base or able to write". |
| `.claude/rules/09-autonomous_execution.txt` | 1 | C13 boundary check now reads "repositories use only `BaseWriteRepository`/`BaseReadRepository`/`$wpdb`". |
| `.claude/rules/11-fluent-database.txt` | 3 | L2 paused-state rule; L8 re-enable step 3; L11–13 the builder-version section rewritten to keep the same three-class split, so a future Fluent swap preserves every module's `extends` clause and the CQRS boundary. |

No file was created and none was deleted.

## Self-check

| Check | Result | Evidence |
| --- | --- | --- |
| Acceptance 1 — grep returns 0 hits (historical excepted) | PASS | `grep -rn BaseRepository .claude/rules/` → **1** hit, `02:77`, which is `Eloquent \`BaseRepository\`` — the Laravel side of a map titled "Laravel wording -> this repo". Nothing else in any rule file. |
| Acceptance 2 — docs reflect the new CQRS tree | PASS | New class names present: `02` ×5, `05` ×1, `09` ×1, `11` ×3. |
| Constraint — no `.php`/code/adapter modified | PASS | `ls -la --time-style=+%H:%M .claude/rules/*.txt` shows exactly the four files at 09:08–09:09; the only `.php` changes in the tree are step 01's, already uncommitted before this step began. |
| Scope — only brief-named files edited | PASS (with note) | The four named files only. Six further hits in two skills found and **not** edited — see D-2026-11-15-06. |
| No rule/skill edited outside the brief | PASS | Skills untouched; `AGENTS.md` untouched by this step. |
| Tests | PASS | "Paused per AGENTS.md" — none written, scaffolded or run. |

## Decisions

### D-2026-11-15-05 — the brief's file names are paraphrases

**Type:** AMBIGUOUS BRIEF.
**Situation:** the brief names `02-framework-adapter-wp.txt`, `05-error-handling.txt` and
`11-test-strategy.txt`. None exists; the real files are `02-framework-adapter-wordpress.txt`,
`05-rich-domain-and-services.txt` and `11-fluent-database.txt`.
**Options:** halt for confirmation; or resolve from the hit counts.
**Decision:** resolved without halting. Each brief estimate (5, 2, 3) matched exactly one real
file's count, and `09-autonomous_execution.txt` + C13 named its target precisely.
**Basis:** 09 A2.2 (non-blocking: a minor ambiguity never halts; take the safest standard path
and record it) and A3 (merely unclear = AMBIGUOUS BRIEF, not H7).
**Impact:** none on the result; the correct four files were edited.
**Proposed AGENTS.md change:** none.
**Status:** OPEN.

### D-2026-11-15-06 — six `BaseRepository` references remain in two skills

**Type:** RULE ERROR.
**Situation:** the acceptance sweep found `BaseRepository` in
`.claude/skills/module-scaffolder/SKILL.md` (lines 40, 87) and
`.claude/skills/update-action-builder/SKILL.md` (lines 70, 114, 139, 267). Line 87 instructs
scaffolding "a `final class` extending `BaseRepository`" — a class that no longer exists.
Line 114 tells the agent to "verify that `findRecordForUpdate()` exists in `BaseRepository`"
and to stop if not.
**Options:** edit them now; or record and defer.
**Decision:** **not edited.** The brief names four rule files, states CORE-PURE mode and a
"documentation and rule consistency pass" over those files; A2.5 forbids touching skills the
brief did not name.
**Basis:** A2.5, §6 SCOPE.
**Impact:** a scaffolding skill would today instruct an agent to extend a deleted class. Both
skills are also **Laravel-flavoured** and already stale independently of this rename:
`module-scaffolder` line 38 cites `.claude/rules/02-framework-adapter-laravel.txt`, which does
not exist in this repo, and `update-action-builder` line 70 describes "raw Eloquent:
`lockForUpdate()`, `fill()`, `isDirty()`, `save()`". Fixing them properly is a review of both
skills against the WordPress rule set, not a rename.
**Proposed AGENTS.md change:** none — the fix belongs in a follow-up brief naming the skills.
**Status:** OPEN — needs a follow-up step.

## Deviations

1. **The brief's acceptance criterion is met for the named files but not globally.** Six hits
   remain in two unnamed skill files (D-2026-11-15-06). Reason for DONE_WITH_NOTES rather than
   DONE.
2. **One hit was kept on purpose.** `02:77` retains `Eloquent \`BaseRepository\`` because it is
   the Laravel-side term in a "Laravel wording -> this repo" map; removing it would leave the
   mapping with nothing to map from. This is the exemption the brief's own acceptance note
   anticipates.
3. **`11-fluent-database.txt` needed more than a rename.** Its "BASEREPOSITORY (builder
   version)" section described a single base class. Left as-is it would have told a future
   re-enabler to collapse the split. Rewritten to prescribe the same three classes with the
   builder, so the swap preserves the CQRS boundary.

## Deferred and open items

- Six `BaseRepository` references in `module-scaffolder` and `update-action-builder` skills
  (D-2026-11-15-06). Both skills also carry Laravel-only assumptions and one non-existent file
  reference; they need a proper review, not a sed pass.
- Step 01's five dead adapters remain non-functional (D-2026-11-15-01) — unchanged by this step.
- `findRecordForUpdate()` still unverified at runtime (`FakeWpdb` cannot parse `FOR UPDATE`).
- `.claude/` is gitignored, so this step's edits are not version-controlled. They exist only on
  this machine; a fresh clone would not carry them. Flagged because it affects the durability
  of the whole rule set, not just this step.

## Tests

Paused per AGENTS.md. No test written, scaffolded, required or run.

## Next step

Review the two skills against the WordPress rule set and the new hierarchy
(D-2026-11-15-06), then resume the per-module repair of the five dead adapters.
