# STEP ID: core/07-autonomous-fix-loop

# GOAL: Formalize the enhanced 2-Round Autonomous Self-Correction Loop (A6b) in `.claude/rules/09-autonomous_execution.txt` and `AGENTS.md`, enforcing bounded purity refactors, strict Failure Signatures (flapping guard), expanded dependency awareness, and strict A10 adherence.

## CONTEXT

To make the Autonomous Fix Loop (`obra/superpowers`-style self-healing) completely bulletproof, we enhance `.claude/rules/09-autonomous_execution.txt` (A6b) with advanced safety guardrails. The self-correction must be strictly bounded, deterministic, and incapable of spiraling into infinite loops or bypassing hard architectural stops.

The core philosophy of this loop is: **Diagnose first -> verify source -> make a bounded repair -> re-check -> detect flapping -> HALT safely.**

## SCOPE FILES

* `.claude/rules/09-autonomous_execution.txt`

* `AGENTS.md`

* `docs/ai-reports/**`

## INSTRUCTIONS (DOCUMENTATION & GOVERNANCE MODE)

Per `AGENTS.md` §0 Startup Protocol, hydrate context (`DECISIONS.md`, `PROJECT-LOG.md`) before editing any file.

### 1. Git Tracking Guard Verification (Exact Procedure)

* Before proceeding, execute the exact verification procedure: `git check-ignore -v .claude/rules .claude/skills docs/ai-reports`.

* If this command returns ANY output, HALT immediately. Do not proceed until tracking is restored.

### 2. Skill Discovery Filename Audit & Rename HALT

* Inspect `.claude/skills/update-action-builder/`.

* If the skill file is named ambiguously (e.g., `update-action-builder-SKILL.md` alongside another `SKILL.md` or in a way that risks overwriting), **HALT immediately**. Do not auto-rename ambiguous files. Alert the developer to manually resolve the naming conflict.

### 3. Formalize Enhanced `A6b AUTONOMOUS FIX LOOP` in `.claude/rules/09-autonomous_execution.txt`

Update `A6 SELF-CHECK` and add `A6b AUTONOMOUS FIX LOOP` right after `A6` with these binding rules:

* **Maximum Repair Rounds:** Bounded to **2 repair rounds** (`Round 1` and `Round 2`) after the initial check (`Round 0`).

* **Failure Signature & Immediate Flapping HALT:**

  * Define **Failure Signature** strictly as the combination of: `[Exact Error Message/Type] + [File Path] + [Line Number]`. (Do not include mutable/uncertain components like Root Cause).

  * If the exact same Failure Signature returns after a repair attempt, **HALT immediately**. Do not attempt Round 2. Same-root-cause failures returning means the fix strategy is flawed.

* **Bounded-Scope Guardrail for Architectural Fixes:**

  * When executing a deep purity leak remediation (e.g., moving `$wpdb` out of a Service) or injecting a missing `executeInTransaction()` wrapper, the refactor must be strictly bounded to the specific method/call causing the failure. The agent is forbidden from rewriting the entire class or unrelated methods to apply the fix.

* **Expanded Contract Dependency Scan:**

  * When an Interface/Contract is modified, the scan must not be limited to `implements`. The agent must scan the **entire project-owned PHP source tree** for the bare `<ContractName>` to locate all injections, usages, and implementations, executing `php -l` and an **explicit interface signature compatibility check** across all hits.

* **A10 Hard Stop Supremacy:**

  * An A10 hard stop (`H1`–`H13`) can **never** be bypassed or overwritten by the A6b auto-fix loop. A10 always forces an immediate HALT (0 repair rounds).

### 4. Wire the Enhanced Fix Loop into `AGENTS.md`

* In `AGENTS.md` **§6 BEHAVIOR** and **§14 COMPLETION CHECKLIST**, add binding references to `09 A6b`, highlighting the Bounded Scope, Failure Signature Flapping Guard, and Expanded Dependency Scan.

### 5. Execute Rule-to-Skill Sync (`A5b`)

* Run the `A5b` sweep across `.claude/skills/**/SKILL.md`.

* **Valid Outcome:** Explicitly report the following as the valid and required outcome if no contradictions are found. Do not omit the section:
  ```text
  STALE_SKILLS_DETECTED: none
  CONTRADICTIONS_DETECTED: none
  ```

## ACCEPTANCE

* Git Tracking Guard exact procedure is documented and executed.

* `.claude/rules/09-autonomous_execution.txt` contains `A6b` with exact Failure Signatures, Immediate HALT on flapping, Bounded-Scope guardrails, Expanded Dependency Scans across the entire PHP source tree, and A10 supremacy.

* `AGENTS.md` references the enhanced Autonomous Fix Loop.

* `A5b` sweep is executed and explicitly reports both `STALE_SKILLS_DETECTED: none` and `CONTRADICTIONS_DETECTED: none` if clean.

* Zero `.php` application files are modified.