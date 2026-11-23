---
name: log-watcher
description: Diagnoses runtime errors from wp-content/debug.log by locating the failing frame, mapping it to the rule that governs it, and reading the actual source before declaring any rule violated. Use when asked to check the error log, investigate a fatal, triage a crash, or find out why the plugin is erroring.
---

# Task: Diagnose a Runtime Error From `wp-content/debug.log`

Find the failure in the log, map it to the rule that governs that code, then **read the source**
and decide whether the rule was actually breached. Report a confirmed violation only with the
source line quoted.

> **Core guiding principle.**
> **The log identifies the failure; the rule matrix identifies where to investigate; the source
> code confirms whether a rule was actually violated.**

The log alone never proves an architectural breach. A `TypeError` in a Controller can be PHP's own
type juggling with no rule involved; a `mysqli` error can come from a third-party plugin. The
matrix tells you *where to look*, never *what is guilty*.

> **This skill modifies nothing.** It reads the log and reads source. It never edits code, never
> fixes the error it finds, never deletes or truncates the log. A fix is a separate, requested step.
>
> **Autonomous runs:** when this skill is executed as a plan step (Autonomous Execution Mode), also follow `.claude/rules/09-autonomous_execution.txt`: put the verification checklist into the step report's Self-check table with evidence, write the step report, and record decisions in `docs/ai-reports/DECISIONS.md`. Where this skill says to stop and ask or tell the developer, apply 09 instead: additive Shared additions and the wiring files are in scope (A4) and are done and recorded, and only the hard stops in A10 halt the run.

---

## Step 0: Locate the log

The log lives at the **WordPress root**, not inside this plugin. Resolve the path for your cwd
before running anything — a wrong path returns empty output, which reads exactly like "no errors":

| Your working directory | Path to the log |
| --- | --- |
| WordPress root (`wp-lead-bridge/`) | `wp-content/debug.log` |
| This plugin's root (`wp-content/plugins/wp-mini-laravel/`) | `../../debug.log` |

There is **no** `debug.log` inside the plugin directory. If a command returns nothing, confirm the
path resolves to an existing file (`ls -la <path>`) before concluding the log is clean. An empty
result from a bad path is the single most common false negative in this workflow.

---

## The Mandatory Diagnostic Pipeline

Run these in order. Each step is a gate: do not start the next until the current one has produced
its output. Skipping to a conclusion from the log is the failure mode this skill exists to prevent.

```
1 LOG ANALYSIS  ->  2 SCOPE GATE  ->  3 MAPPING  ->  4 SOURCE INSPECTION  ->  5 CONFIRM VIOLATION
   find the block     is it ours?      which rule      read the lines        rule breached?
```

### 1. Log Analysis — locate the error block

Do not rely on a bare `tail`: it shows the last lines, which may be a stack-trace fragment whose
head is off-screen. Always grep with `-n` (line numbers) and `-C` (context) so the error's first
line and its trace stay together:

```bash
grep -n -C 5 "Fatal error" ../../debug.log | tail -n 40
```

For a full block including its trace, prefer the line number from the grep and read forward:

```bash
grep -n "Fatal error" ../../debug.log        # get the line numbers first
sed -n '<line>,<line+30>p' ../../debug.log   # read that block in full
```

Record for each distinct error: the **log line number**, the **timestamp**, the **severity**, the
**message**, and the **full stack trace**. A stack trace's frames are the evidence the next step
needs — never summarize them away.

**Census first, then detail.** When the log is large, count before reading:

```bash
grep -c "Fatal error" ../../debug.log
grep -c "PHP Warning" ../../debug.log
grep -c "Uncaught" ../../debug.log
```

**Low-severity entries are contextual evidence, not noise.** Do not discard Warnings and Notices.
A `PHP Warning` immediately before a Fatal is often its cause, and a repeated Warning is often the
real story. Read the entry *above* the fatal and the entries between repeated fatals.

**Severity filtering for effort, never for evidence:**

| Severity | Treatment |
| --- | --- |
| `Fatal error`, `Uncaught`, `TypeError`, `ArgumentCountError`, `Error` | **Primary investigation.** These halt execution. |
| `PHP Warning` | Supporting context. Read them; a warning before a fatal is frequently causal. |
| `PHP Notice`, `Deprecated` | Timeline context. Low priority, still not discarded — a deprecation is a future fatal. |

Deduplicate: one root cause can produce fifty identical blocks. Group by message and count:

```bash
grep "Fatal error" ../../debug.log | sed 's/^\[[^]]*\] //' | cut -c1-160 | sort -u
```

### 2. Scope Gate — is the failing frame ours?

**This gate is mandatory and comes before mapping.** Not every fatal in the log can be a violation
of this project's rules; most, in practice, are not. Determine which code the failing frame belongs
to, then route accordingly.

Read the stack trace's frames and classify each path:

| Frame path points into | Classification | Action |
| --- | --- | --- |
| `wp-content/plugins/wp-mini-laravel/{app,core,framework,routes,config,views,database}/` | **IN SCOPE** | Proceed to Step 3 (Mapping). |
| `wp-content/plugins/<other-plugin>/` | **OUT OF SCOPE** — third party | Report the owning plugin and path. Our rules do not govern its code. |
| `wp-admin/`, `wp-includes/` | **OUT OF SCOPE** — WordPress core | Report it. A core fatal is a core/WP-version/host issue. |
| `vendor/` (this plugin's) | **OUT OF SCOPE** — dependency | A dependency fatal is a package/version issue, not a rule breach. |
| A file that no longer exists / a `*.probe-*.php` / a temp script | **STALE ENTRY** | The log records a past run of a deleted file. Report it as historical, not live. |
| No file frame at all (parse error, autoload failure, memory limit) | **OUT OF SCOPE** — environment | Version mismatch, memory, include path. |

Report every distinct error under one of two headings — `IN SCOPE` or `OUT OF SCOPE` — so the
developer can see at a glance what is actionable:

```
OUT OF SCOPE: 4 of 6 fatals
  - wedevs-academy/vendor/autoload.php       third-party plugin (missing dependency)
  - wp-admin/includes/plugin.php             WordPress core
  - composer platform check (PHP >= 8.2.0)   environment (PHP version)
  - .probe-del2.php                          stale entry (file deleted)

IN SCOPE: 1 distinct fatal
  - app/Http/Controllers/Admin/CompanyController.php:204  -> continue to Mapping
```

An entry whose trace never reaches this plugin's own directories **cannot** be a violation of this
project's rules. Saying so plainly is a correct outcome; it is not a failure to find something.

**Only if at least one frame is IN SCOPE** does the next step apply. If nothing is in scope, skip
to the report (Step 5) and state that no in-scope failure was found.

### 3. Mapping — find the Primary Investigation Rule

Consult this matrix to find **where to look**. The third column is the point of this whole skill:
a mapping is a *lead*, and its confidence tells you how much weight it carries.

| Error / Exception Type | Primary Investigation Rule | Confidence & Rationale |
| --- | --- | --- |
| `mysqli_sql_exception`, SQL syntax errors, `$wpdb` query failures | `.claude/rules/02-framework-adapter-wordpress.txt` §7 (Persistence) | **High** — direct `$wpdb`/query mismatch or a missing `prepare()` |
| `TypeError`, `ArgumentCountError` | `.claude/rules/05-rich-domain-and-services.txt` R4 (Service Rules) / R8 (Port Shape) | **Medium / heuristic** — may be PHP core type juggling, a strict-typing slip, `$wpdb` returning strings, or Mapper coercion. Inspect before concluding. |
| `ServiceNotFoundException`, DI container / binding errors, "target is not instantiable" | `.claude/rules/02-framework-adapter-wordpress.txt` §5 (Composition Root and DI) | **High** — a missing or mis-scoped binding |
| Uncaught Domain Exception escaping a Service | `.claude/rules/05-rich-domain-and-services.txt` R5 (Command Service, DRY transaction wrapper) | **High** — Result Pattern or transaction-boundary slip |
| `DuplicateKeyException` / MySQL errno 1062 surfacing as a vendor error | `.claude/rules/02-framework-adapter-wordpress.txt` §7.7 | **High** — the translator did not catch it; a vendor exception reached Core |
| Anything else, or no match | `AGENTS.md` + the rule for the failing *layer* | **Low** — map by the frame's directory (Core vs adapter vs delivery), not by exception name |

Rules for using this matrix:

- It names a **Primary Investigation Rule**, never a verdict. Nothing here says a rule *was* broken.
- **Low confidence is a signal to read more, not to report harder.** A `TypeError` row is
  deliberately heuristic: PHP raises it for reasons that have nothing to do with architecture.
- A row that does not match is not a dead end — map by the failing frame's layer instead (a
  `Domain/` frame maps to `05`, an `Infrastructure/` frame to `02` §7, a `routes/` frame to §6).
- If a stale log entry names a rule that has since been renamed, verify the rule file exists on
  disk before citing it. Rules get renumbered; the log does not.

### 4. Source Inspection — read the actual code

Open the real file at the exact frame from the trace and read it. Do not reason from the error
message, the exception class name, or the method name alone.

1. Take the topmost frame whose path is **in this plugin** (`app/`, `core/`, `framework/`,
   `routes/`). That file and line is the subject.
2. Read that line and its surrounding method — not just the line. The cause is usually one frame
   up, in the argument the call received.
3. Walk outward through the plugin's own frames until the boundary between layers, or until the
   argument's origin is visible.
4. Note what the code **actually does** at that line: the real signature, the real call, the real
   value being passed. Quote it.

```bash
sed -n '<line-15>,<line+15>p' app/Http/Controllers/Admin/CompanyController.php
```

Verify the frame is still valid before trusting it. A log entry is a snapshot: the file may have
changed, the method may have moved, the line may no longer hold that call. If the quoted line does
not match the trace, report it as a **stale entry** and stop — do not map a rule to code that no
longer exists.

### 5. Confirm Violation — and report in the strict 4-part structure

Only now, with the source read, decide. Three honest outcomes:

1. **CONFIRMED VIOLATION** — the source at that line does something a cited rule forbids. Quote the
   rule and the line.
2. **NO VIOLATION** — the source is compliant; the error has another cause (bad data, environment,
   a dependency, an upstream caller). Say so, with the evidence that clears it.
3. **INCONCLUSIVE** — the plugin's frame is too far down the trace (or the entry is stale) to
   settle it. Say what would settle it.

All three are legitimate results. **Reporting "no violation found" is a success, not a failure** —
a false "Rule Violated" is the one outcome this skill is built to prevent.

Report using this exact structure, one block per distinct error:

```
### 1. Log Evidence
  File:    <resolved log path>
  Lines:   <start>-<end> (log line numbers)
  Time:    <timestamp>
  Severity: <Fatal error | Warning | ...>
  Message: "<exact message>"
  Trace:   <the plugin-owned frames, verbatim>

### 2. Primary Investigation Area
  Rule:       <.claude/rules/0X-...txt> <section>
  Confidence: <High | Medium/heuristic | Low>
  Why:        <the matrix row that matched, or the layer mapping if none did>

### 3. Source Inspection Finding
  Read:   <file>:<line>  (the plugin-owned frame from the trace)
  Code:   <the actual lines, quoted>
  Finding: <what this code really does; whether the frame still matches the trace>

### 4. Confirmed Violation & Compliant Fix
  Verdict: CONFIRMED VIOLATION | NO VIOLATION | INCONCLUSIVE
  Rule text: "<the exact sentence from the rule, quoted>"   (only if CONFIRMED)
  Breach:    <what the code does vs what the rule requires> (only if CONFIRMED)
  Fix:       <the precise correction>                       (only if CONFIRMED)
```

If the verdict is NO VIOLATION or INCONCLUSIVE, part 4 states the verdict and the reason, and
proposes no fix. Do not fill in a fix to make the report look complete.

---

## Worked example: the two shapes a real diagnosis takes

Both blocks below are from the live log. They show the pipeline separating a real finding from a
false lead — the whole reason the Scope Gate exists.

**OUT OF SCOPE** — trace never enters our plugin:

```
### 1. Log Evidence
  Message: "PHP Fatal error: Uncaught Error: Failed opening required
            '.../plugins/wedevs-academy/vendor/autoload.php'"
  Trace:   first frame is wedevs-academy/vendor/autoload.php

### 2. Primary Investigation Area
  n/a — out of scope. The failing frame is a third-party plugin's autoloader.

### 3. Source Inspection Finding
  Not performed. No frame points into app/, core/, framework/ or routes/.

### 4. Confirmed Violation & Compliant Fix
  Verdict: OUT OF SCOPE (third-party plugin).
  The plugin's own vendor/ is missing or uninstalled. No rule in this project governs it.
  Fix: outside this project — reinstall/rebuild that plugin's dependencies.
```

**IN SCOPE** — trace lands in our Controller. This one is a real entry from the live log, and it
demonstrates the **stale-frame check** from Step 4, so it is worth reading closely:

```
### 1. Log Evidence
  Message: "PHP Fatal error: Uncaught InvalidArgumentException:
            No route named [companies.create] is registered."
  Trace:   #0 framework/Support/helpers.php(353): UrlGenerator->route('companies.creat...')
           #1 app/Http/Controllers/Admin/CompanyController.php(204): route('companies.creat...')
           #2 .probe-del2.php(15): CompanyController->index(...)   <- temp probe, deleted since

### 2. Primary Investigation Area
  Rule: .claude/rules/02-framework-adapter-wordpress.txt §6 (Delivery Flow)
  Confidence: Medium — the exception is a framework routing error, mapped by layer.

### 3. Source Inspection Finding
  Read:   app/Http/Controllers/Admin/CompanyController.php:204
  Code:   (line 204 does not contain that call — see below)
  Finding: STALE FRAME. The trace was written 10-Nov; the file has changed since. The
           call now sits at lines 61 and 131, so the log line number no longer maps to
           the code. Per Step 4, a frame that no longer matches is reported as stale
           rather than mapped to a rule.

### 4. Confirmed Violation & Compliant Fix
  Verdict: INCONCLUSIVE for the log entry as written (stale frame).
  What would settle it: re-run the probe that produced the entry, or reproduce the
  index action now, and check whether route('companies.create') resolves. The live
  call sites (61, 131) can be inspected directly, but the log line cannot be trusted
  to point at them.
```

Two lessons this example carries, both easy to get wrong:

- **A log entry is a snapshot, not a live pointer.** The frame said line 204; the call had moved
  to 61 and 131. Reporting "line 204 violated §6" would have been false about the code even
  though it was true about the log.
- **The trace's deepest frame was a temp probe file** (`.probe-del2.php`, since deleted). The
  failure was produced by a manual probe, not by a real request — which is why the Scope Gate
  looks at *every* frame, not just the first.

---

## Verification checklist (self-check against what you actually did)

Claim only what you can point to in the log output or a file you opened. A false claim is a defect.

- [ ] The log path resolved to a real file before any conclusion (`ls -la` on it), and the cwd it
      was resolved from is stated.
- [ ] Every error block was located with `grep -n` (line numbers), not a bare `tail`.
- [ ] The stack trace was read in full for every in-scope error, not just the message line.
- [ ] The Scope Gate ran **before** any rule was named, and out-of-scope entries are reported as
      out of scope with their owning path.
- [ ] Low-severity entries were read as context, and any fatal's preceding warning was examined.
- [ ] Distinct errors were deduplicated and counted.
- [ ] For every rule cited, the **matrix row and its confidence** are stated; heuristics are
      labeled as heuristics.
- [ ] Every rule file cited was confirmed to exist on disk.
- [ ] The source at the exact trace frame was opened and quoted — never described from the
      exception class or method name.
- [ ] The frame was checked against the current file; a mismatch is reported as a stale entry.
- [ ] The verdict is one of CONFIRMED VIOLATION / NO VIOLATION / INCONCLUSIVE, and "no violation"
      is reported as a valid result when the evidence supports it.
- [ ] No fix is proposed for a NO VIOLATION or INCONCLUSIVE verdict.
- [ ] The strict 4-part structure is used per error.
- [ ] Nothing was modified: no code edited, no log deleted, truncated or rotated, no temp file left.
- [ ] No command was run that changes state; this skill is read-only.
