# STEP ID: core/09-pre-commit-hooks

# GOAL: Implement an enterprise-grade, bulletproof local Git pre-commit hook (`.git/hooks/pre-commit`) to enforce automated pre-flight checks (quoted filename syntax linting via `php -l`, extended debugging function detection, and precise actionable failure messaging).

## CONTEXT

To elevate our workflow to advanced `obra/superpowers` methodology, we automate pre-flight quality gates. The local Git pre-commit hook inspects staged files safely (handling spaces in filenames via proper variable quoting), checks for syntax errors, scans for extended debugging artifacts (`dd`, `dump`, `var_dump`, `print_r`, `var_export`, `console.log`), and delivers crystal-clear, actionable error messages when blocks occur.

## SCOPE FILES

* `.git/hooks/pre-commit` (New executable script)
* `docs/ai-reports/**`

## INSTRUCTIONS (IMPLEMENTATION & GOVERNANCE MODE)

Per `AGENTS.md` §0 Startup Protocol, hydrate context before executing the following steps:

### 1. Create the Enterprise-Grade Pre-Commit Hook Script (`.git/hooks/pre-commit`)

Create an executable shell script at `.git/hooks/pre-commit` featuring the following robust pipeline:

* **Trigger:** Runs automatically on `git commit`.

* **Check 1: Staged PHP Linting with Filename Quoting (`php -l "$file"`):**
  * Find all staged files safely and iterate with proper variable quoting to prevent crashes on spaces:
    ```bash
    git diff --cached --name-only --diff-filter=AM | while read -r file; do
        if [[ "$file" == *.php ]]; then
            if ! php -l "$file" > /dev/null 2>&1; then
                echo "Commit Blocked! ❌ PHP Syntax error detected in '$file'."
                php -l "$file"
                exit 1
            fi
        fi
    done
    ```

* **Check 2: Extended Debugging Patterns Detection (with Line Numbers):**
  * Scan staged files for forbidden debugging leftovers: `dd(`, `dump(`, `var_dump(`, `print_r(`, `var_export(`, and `console.log(`.
  * If found, halt the commit with a graceful, actionable error message displaying the exact filename and line number.
  * **Actionable Failure Message Format:**
    `"Commit Blocked! ❌ Debugging statement 'dd()' found in OrderService.php at line 42. Please remove it and try again."`

* **Check 3: Git Tracking Guard Verification:**
  * Run `git check-ignore -v .claude/rules .claude/skills docs/ai-reports` to ensure governance files are never accidentally ignored or untracked.

### 2. Make the Hook Executable

* Run `chmod +x .git/hooks/pre-commit` to ensure the hook executes locally.

### 3. Documentation & Project Log

* Record the integration in `docs/ai-reports/PROJECT-LOG.md` and append decision details to `docs/ai-reports/DECISIONS.md`.

## ACCEPTANCE

* `.git/hooks/pre-commit` handles spaces in filenames via proper quoting (`"$file"`).
* Extended debug patterns (`dd`, `dump`, `var_dump`, `print_r`, `var_export`, `console.log`) trigger an immediate, actionable block message specifying the filename and line number.
* Zero unexpected application code modifications occur.