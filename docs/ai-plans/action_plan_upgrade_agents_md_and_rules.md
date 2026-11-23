# STEP ID: core/05-upgrade-agents-and-execution-rules

# GOAL: Enhance `AGENTS.md` and `09-autonomous_execution.txt` with Startup Protocols, Self-Healing Skill Sync, Git Visibility Checks, and Structured HALT Formatting.

## CONTEXT

During recent refactoring passes, we identified operational gaps in our agentic workflow:
1. Rules were updated in `.claude/rules/`, leaving `.claude/skills/` stale with outdated Laravel/Eloquent patterns.
2. The `.claude/` directory was accidentally ignored in `.gitignore`, risking loss of architectural rules across machines.
3. New agent sessions benefit from an explicit startup routine that loads recent architectural decisions (`DECISIONS.md` and `PROJECT-LOG.md`) before touching files.
4. When a `HALT` condition triggers, a standardized diagnostic output accelerates architectural decision-making.

## INSTRUCTIONS (CORE-PURE / DOCUMENTATION MODE)

Audit and update `.gitignore`, `AGENTS.md`, and `.claude/rules/09-autonomous_execution.txt` with the following enhancements while preserving all existing domain, CQRS, and WordPress rules:

### 1. Fix Git Visibility (`.gitignore`)
* Inspect `.gitignore` in the repository root.
* If `.claude/` or `.claude/*` is ignored, remove that exclusion so that `.claude/rules/` and `.claude/skills/` are tracked by Git. (If local cache/temp files exist inside `.claude/`, ignore only those specific cache files, never `rules/` or `skills/`).

### 2. Add Startup & Pre-Flight Protocol (`AGENTS.md`)
* Add a concise **STARTUP & PRE-FLIGHT PROTOCOL** section near the top of `AGENTS.md` instructing the agent to:
  1. **Context Hydration:** Read the last 3 entries of `docs/ai-reports/DECISIONS.md` and the latest entry in `docs/ai-reports/PROJECT-LOG.md` before executing any step.
  2. **Git Tracking Guard:** Verify that `.claude/rules/`, `.claude/skills/`, and `docs/ai-reports/` are not blocked by `.gitignore`. If they are ignored, trigger a HALT condition and notify the user.

### 3. Add Self-Healing Rule-to-Skill Synchronization (`AGENTS.md` & `09-autonomous_execution.txt`)
* Add a binding rule stating:
  * Whenever a step modifies any file in `.claude/rules/` or architectural sections of `AGENTS.md`, the agent **MUST** run a grep/audit across `.claude/skills/*/SKILL.md` for terms, class names, or rule references impacted by that change.
  * If stale references are found in `.claude/skills/` and they are outside the current step's write scope, the agent must list the exact skill file paths and line numbers in its completion report under a `STALE_SKILLS_DETECTED` heading.

### 4. Standardize the Structured HALT Output (`09-autonomous_execution.txt`)
* Update the HALT handling rules so that whenever a HALT is triggered, the agent formats its response using this exact structure:
  * **1. Broken Rule / Premise:** Quote the exact section in `AGENTS.md` or `.claude/rules/` that is violated.
  * **2. Conflicting Files:** List the exact file paths and line numbers on disk causing the conflict.
  * **3. Architectural Options:** Present 2 to 3 concrete, actionable options (e.g., Option A, Option B) with clear trade-offs so the user can resolve the blocker in a single reply.

## ACCEPTANCE

* `.claude/rules/` and `.claude/skills/` are no longer ignored by `.gitignore` (`git check-ignore .claude/rules .claude/skills` returns no matches).
* `AGENTS.md` contains the Startup Context Hydration and Git Tracking Guard rules.
* Both `AGENTS.md` and `.claude/rules/09-autonomous_execution.txt` enforce the Rule-to-Skill synchronization check (`STALE_SKILLS_DETECTED`).
* `.claude/rules/09-autonomous_execution.txt` defines the 3-part Structured HALT template.
* No `.php` application or infrastructure code is modified during this step.