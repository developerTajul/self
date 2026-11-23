# STEP ID: core/06-log-watcher-skill-enhanced

# GOAL: Create an advanced, safe Claude diagnostic skill (`.claude/skills/log-watcher/SKILL.md`) that enforces strict source verification, line-number grepping, contextual evidence preservation, and a non-assumption diagnostic pipeline.

## CONTEXT

To make the Automated Error Log Watcher robust and completely free of premature conclusions, we adopt the core architectural principle:
> **"The log identifies the failure; the rule matrix identifies where to investigate; the source code confirms whether a rule was actually violated."**

Raw `tail` inspection or jumping straight to a "Rule Violated" conclusion without checking source code leads to false positives. We must enforce a mandatory sequence: **`Log -> Mapping -> Source Inspection -> Confirm Violation`**.

## INSTRUCTIONS (DOCUMENTATION & SKILL CREATION MODE)

Per AGENTS.md §0 Startup Protocol, hydrate context before executing the following steps within scope:

### 1. Create the Enhanced Log Watcher Skill (`.claude/skills/log-watcher/SKILL.md`)

Create or update `.claude/skills/log-watcher/SKILL.md` with these exact guardrails:

* **Core Guiding Principle:**
  * *The log identifies the failure; the rule matrix identifies where to investigate; the source code confirms whether a rule was actually violated.*

* **Mandatory Diagnostic Pipeline (Sequence):**
  1. **Log Analysis:** Locate the error block.
  2. **Mapping:** Consult the Log-to-Rule Matrix to find the **Primary Investigation Rule**.
  3. **Source Inspection:** Read the actual source file/lines using exact line numbers.
  4. **Confirm Violation:** Only after inspecting the source code, declare whether an architectural rule was breached.

* **Step 1 (Contextual Grepping, Line Numbers & Low-Severity Evidence):**
  * Do not rely solely on raw `tail`. Always include line numbers using `grep -n` and inspect context:
    `grep -n -C 5 "Fatal error" wp-content/debug.log | tail -n 40`
  * **Low-Severity Logs as Contextual Evidence:** Do not discard routine Notices or Warnings as pure noise. Treat them as contextual timeline evidence (e.g., prior warnings that led to a downstream Fatal Error).
  * **Severity Filtering:** Focus primary investigation on Fatal Errors, Uncaught Exceptions, and TypeErrors, while keeping lower-severity entries as supporting context.

* **Step 2 (Log-to-Rule Mapping Matrix):**
  Use this embedded matrix inside the skill. Note that columns point to **Primary Investigation Rule** (not a final guilty verdict), and certain patterns carry heuristic/medium confidence:

  | Error / Exception Type | Primary Investigation Rule | Confidence & Rationale |
  | --- | --- | --- |
  | `mysqli_sql_exception`, SQL syntax errors | `02-framework-adapter-wordpress.txt` §7 | High (Direct `$wpdb` / query mismatch) |
  | `TypeError`, `ArgumentCountError` | `05-rich-domain-and-services.txt` | **Medium confidence / heuristic** (Could be PHP core type juggling, strict typing slip, or Mapper coercion issue) |
  | `ServiceNotFoundException`, DI container errors | `02-framework-adapter-wordpress.txt` §5 | High (Composition root / DI binding missing) |
  | Uncaught Domain Exception in Service | `05-rich-domain-and-services.txt` R5 | High (Result Pattern / Transaction Boundary slip) |

* **Step 3 (Structured Diagnosis Output):** Present findings using the strict 4-part structure:
  1. **Log Evidence:** Exact file path, line number, and error snippet from `debug.log`.
  2. **Primary Investigation Area:** Rule file and section pointed to by the matrix.
  3. **Source Inspection Finding:** What the actual code at that file and line does.
  4. **Confirmed Violation & Compliant Fix:** Verified breach of CQRS/Hexagonal boundaries and the precise code correction.

### 2. Update `AGENTS.md`

* Add **§15 RUNTIME DIAGNOSTICS & LOG WATCHER** binding agents to the `Log -> Mapping -> Source Inspection -> Confirm Violation` pipeline when investigating runtime errors in `wp-content/debug.log`.

### 3. Rule-to-Skill Sync Check

* Per AGENTS.md §6 and `09-autonomous_execution.txt` A5b, perform a sweep to ensure no other skills conflict with this enhanced pipeline.

## ACCEPTANCE

* `.claude/skills/log-watcher/SKILL.md` mandates line-number grepping (`grep -n`), low-severity context preservation, `Primary Investigation Rule` naming, and the strict 4-step sequence.

* `AGENTS.md` integrates the updated diagnostic protocol.

* No `.php` application code is modified during this step.