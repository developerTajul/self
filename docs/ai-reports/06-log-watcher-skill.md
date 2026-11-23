# Step 06 — Log Watcher Skill (`core/06-log-watcher-skill-enhanced`)

**Status:** DONE_WITH_NOTES
**Date:** 2026-11-15
**Branch:** `wpdb`
**Plan:** `docs/ai-plans/action_plan_automated_error_log_watcher_skill_enhanced.md`
**Previous steps:** `01`–`05` (see `PROJECT-LOG.md`)

## Summary

Created `.claude/skills/log-watcher/SKILL.md` — a read-only diagnostic skill that runs
`Log -> Scope Gate -> Mapping -> Source Inspection -> Confirm Violation` — and added
`AGENTS.md` §15 binding the same pipeline whenever a runtime error in `wp-content/debug.log` is
investigated. The skill embeds the plan's 4-row Log-to-Rule Matrix, whose targets were each
verified to exist and to say what the matrix claims (`02` §7 Persistence, `02` §5 Composition
Root and DI, `05` R5 Command Service).

Two additions beyond the plan's 4 steps, both raised with and confirmed by the developer first:
a **Scope Gate** between Log Analysis and Mapping (D-2026-11-15-16), and **both** log paths with
their cwd (D-2026-11-15-17). The gate was not a stylistic preference: the live log's fatals come
from a deleted temp probe, a third-party plugin, WordPress core and a PHP version check, and the
plan's pipeline as written would have mapped those to this project's rules.

Working the real log also produced the report's most useful finding: the one genuinely in-scope
frame is a **stale entry**. The trace says `CompanyController.php:204`; that line no longer holds
the call, which now sits at 61 and 131. The skill's worked example therefore teaches the
stale-frame check from Step 4 rather than presenting a clean confirmation — a better case, because
reporting "line 204 violated §6" would have been false about the code while true about the log.

## Inputs used

The plan; `AGENTS.md` §0/§6/§14 and the rule index; `.claude/rules/02-framework-adapter-wordpress.txt`
(§5, §6, §7), `.claude/rules/05-rich-domain-and-services.txt` (R4, R5, R8);
`docs/ai-reports/04-agents-and-rules-upgrade.md` (A5b, the sweep this step triggers);
`docs/ai-reports/PROJECT-LOG.md` and the last three decisions (per §0).

**The live log itself** — `wp-content/debug.log`, 29,788 bytes, read as the reference for every
claim about severity, path and frame shape:

| Measured | Value |
| --- | --- |
| Distinct fatal messages | 4 |
| `Fatal error` blocks | 6 (2 distinct root causes repeated) |
| `PHP Warning` | 65 |
| `PHP Notice` | 0 |
| `Uncaught` | 6 |
| Log entries containing `mysqli` | 1 — a **Warning** (`mysqli_real_connect(): HY000/2002 connection refused`), not the `mysqli_sql_exception` the matrix's first row names |

Frame ownership across the whole log, counted from the traces:

| Owner | Frames |
| --- | --- |
| WordPress core (`wp-admin`) | 7 |
| our `tests/manual/probe-*.php` scripts | 4 |
| our `vendor/` (dependencies) | 2 |
| **our `app/` — IN SCOPE** | **2** |
| **our `framework/` — IN SCOPE** | **2** |
| other / outside this plugin | 4 |

Both in-scope locations are the same fault: `app/Http/Controllers/Admin/CompanyController.php:204`
and `framework/Support/helpers.php:353`, from the `companies.create` row. No frame anywhere in the
log points into `core/` or `routes/`.

## Rules and skills applied (PROCESS GUARD, before editing)

- §0 STARTUP PROTOCOL — Git Tracking Guard passed (`git check-ignore -q .claude/rules`,
  `.claude/skills`, `docs/ai-reports` all exit 1 = tracked). Context Hydration read
  `PROJECT-LOG.md` (through `core/05`) and the last 3 decisions (D-…-13/14/15).
- §6 SCOPE + FILE EXISTENCE GUARD — `.claude/skills/log-watcher/` verified absent before creation
  (`ls` → "No such file or directory"), so creating it is authorised, not a duplicate.
- §6 RULE-TO-SKILL SYNC / 09 A5b — this step edits `AGENTS.md` and creates a skill, so the sweep
  ran; see `STALE_SKILLS_DETECTED`.
- §2 MODE DETECTION — documentation/skill mode. No `core/`, `app/`, `framework/` or `vendor/`
  file edited, as this step's acceptance requires.
- 09 A9 precedence 1 — the two additions to the plan's pipeline were put to the developer and
  chosen explicitly, rather than decided unilaterally against an explicit plan instruction.
- Tests: **Paused per AGENTS.md.** No test written, scaffolded or run.

## What the plan asked for, and where it is

| Plan item | Delivered |
| --- | --- |
| §1 Core guiding principle | Skill header blockquote, verbatim, and again in `AGENTS.md` §15 |
| §1 Mandatory pipeline | 5 steps (`### 1`–`### 5`), each a gate: Log Analysis, Scope Gate, Mapping, Source Inspection, Confirm Violation |
| §1 Step 1: `grep -n -C` with line numbers | Step 1, with three ready commands; the plan's example command runs as written (smoke-tested) |
| §1 Step 1: low-severity as context | Step 1 "Low-severity entries are contextual evidence, not noise" + a severity table where severity ranks *effort*, never evidence |
| §1 Step 2: the matrix | Step 3, all four rows, each target verified on disk; the `TypeError` row keeps its **Medium / heuristic** label and adds the sections (R4/R8) it maps to |
| §1 Step 3: 4-part output | Step 5, exact structure, plus a three-outcome verdict model (CONFIRMED / NO VIOLATION / INCONCLUSIVE) |
| §2 AGENTS.md §15 | Added, in correct numeric order after §14 |
| §3 Rule-to-skill sync | A5b sweep run; `STALE_SKILLS_DETECTED: none` |

## STALE_SKILLS_DETECTED

A5b sweep, run because this step edited `AGENTS.md` and created a `SKILL.md`. Swept terms:
`AGENTS.md §14` / `s14` / `section 14`, `§15` / `s15`, `log-watcher`, `debug.log`,
`Rule Violated`, `error log`, `tail ` — **7 skill files, 17 hits, all inside the new
`log-watcher` skill itself.** No other skill cites an AGENTS section number in a way this step
invalidated, and none carries conflicting error-handling guidance.

**`STALE_SKILLS_DETECTED: none`** — no stale references outside the step's own scope.

## Self-check table

| Check | Result | Evidence |
| --- | --- | --- |
| C1 files | PASS | `.claude/skills/log-watcher/SKILL.md` created; `AGENTS.md` modified; report + `DECISIONS.md` + `PROJECT-LOG.md` in `docs/ai-reports/` |
| C2 scope | PASS | plan names the skill and `AGENTS.md` §15; no other file touched |
| C3 syntax | N/A | Markdown only; no `.php` file touched, so 09 A7's `php -l` has no subject |
| C4 autoload | N/A | no namespace path added |
| C5 DoD | PASS | the skill's own 15-item checklist is present and matches what the skill mandates |
| C6 `core/` greps | N/A | `core/` not touched |
| C7 wiring | N/A | no Contract, route or provider changed |
| C8 schema | N/A | no schema |
| C9 leftovers | PASS | no temp file created; the log was not modified, truncated or rotated |
| C10 runtime | PASS (read-only) | every command in the skill was run against the live log to confirm it works; nothing was written |
| C11 tests | PASS | "Paused per AGENTS.md", none claimed |
| C12 transactions | N/A | no write path |
| C13 boundaries | PASS | no `Core\`/`App\`/`Framework\` file touched |
| C14 file existence | PASS | `.claude/skills/log-watcher/` confirmed absent before creation; `AGENTS.md` and both rule files confirmed present before citing them |
| C15 rule-to-skill sync | PASS | A5b ran; `none`, with the swept term list above |

## Acceptance criteria (plan) vs. result

| Criterion | Result |
| --- | --- |
| Skill mandates `grep -n` | **Met** — Step 1, 3 occurrences |
| Low-severity context preservation | **Met** — dedicated paragraph + severity table |
| `Primary Investigation Rule` naming | **Met** — 3 occurrences, including the matrix column header |
| Strict 4-step sequence | **Met, extended to 5** by the Scope Gate (D-2026-11-15-16) |
| `AGENTS.md` integrates the protocol | **Met** — §15, verified in numeric order after §14 |
| No `.php` application code modified | **Met** — `git status` shows 0 `.php` files from this step |

## Commands run

```
git check-ignore -q .claude/rules .claude/skills docs/ai-reports   # exit 1 = tracked
ls -la .claude/skills/log-watcher/                                  # absent before creation
ls -la ../../debug.log                                              # 29,788 bytes, exists
grep -c "Fatal error" ../../debug.log                               # 6
grep -c "PHP Warning" ../../debug.log                               # 65
grep -n -C 5 "Fatal error" ../../debug.log | tail -n 40             # the skill's own command
grep "Fatal error" ../../debug.log | sed 's/^\[[^]]*\] //' | sort -u  # 4 distinct messages
grep -n -i "mysqli" ../../debug.log                                 # 1 hit, a Warning
sed -n '200,208p' app/Http/Controllers/Admin/CompanyController.php  # stale-frame check
grep -n "companies.create" app/Http/Controllers/Admin/CompanyController.php  # 61, 131
```

## Decisions (D-IDs)

`D-2026-11-15-16` Scope Gate added before Mapping (developer-confirmed). `D-2026-11-15-17` both
log paths taught, resolved per cwd (developer-confirmed). `D-2026-11-15-18` the matrix's
`mysqli_sql_exception` row does not match the live log's only `mysqli` entry, which is a Warning —
recorded as a matrix limitation, not corrected. Full text in `DECISIONS.md`.

## Deviations

1. **The pipeline has 5 steps, not the plan's 4** — the Scope Gate was inserted before Mapping.
   Raised with the developer, who chose it, after the live log showed that all 6 fatals come from
   a deleted probe file, a third-party plugin, WordPress core and a PHP version check. Without the
   gate the matrix maps those to this project's rules. See D-2026-11-15-16.
2. **Two log paths are taught**, not the plan's single `wp-content/debug.log`. That path is correct
   from the WordPress root but wrong from this project's working directory (the plugin root), where
   the real path is `../../debug.log` — verified: there is no `debug.log` inside the plugin. See
   D-2026-11-15-17.
3. **The worked example reports a stale frame rather than a clean confirmation.** The live
   in-scope trace names `CompanyController.php:204`, which no longer holds that call (now 61, 131).
   The example teaches the Step 4 stale-frame check instead of asserting a violation the source
   does not currently support.
4. **AGENTS.md §15 was briefly placed before §14** and corrected in the same step to numeric order
   (§13 → §14 → §15 → index). Recorded because the self-check caught it, not because it survived.

## Deferred and open items

- **`mysqli_sql_exception` may never appear in this log.** The matrix's first row is the only
  High-confidence `$wpdb` row, and the log's single `mysqli` entry is a connection Warning. If
  `$wpdb` failures surface as `\RuntimeException` (which `02` §7.6 requires adapters to throw),
  the matrix's first row is aimed at the wrong exception class (D-2026-11-15-18).
- **`05`'s `TypeError` row remains heuristic by design** and is labeled so; the live log's
  `TypeError` (`array_keys(): null given`) has its `#0` frame in WordPress core, so it is out of
  scope and correctly never reaches that row.
- **No `.php` file was fixed.** The one in-scope fault (`route('companies.create')`) is now a stale
  log entry whose cause may already be resolved; diagnosing was this step's scope, fixing is not.
- **Nothing is committed.** `git add`/`commit` is a forbidden A7 operation.
- Steps `01`/`02` items unchanged: five dead Eloquent adapters, `findRecordForUpdate()` unverified
  at runtime (`FakeWpdb` cannot parse `FOR UPDATE`).

## Tests

Paused per AGENTS.md. No test written, scaffolded, required or run.

## Next step

Commit `core/01`–`core/06`, or resume the per-module repair of the five dead Eloquent adapters
(`core/01` D-2026-11-08 item).
