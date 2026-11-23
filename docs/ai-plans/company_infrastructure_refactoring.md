# Action Plan: Company Module Infrastructure Refactoring

## Objective
Migrate the Company module's database layer from the Fluent Query Builder to WordPress's native `$wpdb` approach, in compliance with the newly added `D7 WPDB OVERRIDE` rule in `AGENTS.md`.

## Prerequisites
- [x] Create and checkout a new Git branch dedicated to this refactoring.
- [x] Update `AGENTS.md` to pause Rule D6 and add `D7 WPDB OVERRIDE`.
- [x] Execute `composer dump-autoload` to ensure the classmap is fresh.

## Step 1: Refactor `BaseRepository`
**File:** `core/Shared/Infrastructure/Repositories/BaseRepository.php`
- Remove `QueryBuilderInterface` from the constructor and class imports.
- Inject or globally access `$wpdb`.
- Reimplement the foundational methods (`insert`, `updateRow`, `deleteRow`, `rowExists`) using `$wpdb->insert()`, `$wpdb->update()`, `$wpdb->delete()`, and `$wpdb->get_var()`.
- Ensure all exceptions are properly caught and passed through the `duplicateException` hook (handling WordPress/MySQL error codes like `1062` for unique constraints).

## Step 2: Refactor `WpCompanyWriteRepository`
**File:** `core/Company/Infrastructure/Repositories/WpCompanyWriteRepository.php`
- Remove any `Framework\Database\Query\...` imports.
- Ensure the class correctly extends the newly refactored `BaseRepository`.
- Reimplement any custom write methods using the parent methods or direct `$wpdb` queries.
- Ensure the domain exception hook (`CompanyDuplicateException`) accurately detects `$wpdb` unique constraint errors.

## Step 3: Refactor `WpCompanyReadRepository`
**File:** `core/Company/Infrastructure/Repositories/WpCompanyReadRepository.php`
- Remove `QueryBuilderInterface` from the constructor and all related imports.
- Reimplement all read and list operations (e.g., `findById`, `getFilteredCompanies`) using `$wpdb->get_results()`, `$wpdb->get_row()`, etc.
- **Critical:** Secure every single raw SQL query using `$wpdb->prepare()` to prevent SQL injection.
- Ensure complex operations like pagination, filtering, and JOINs are properly formatted in raw SQL.

## Step 4: Verification and Cleanup
- [x] Check for any leftover `Framework\Database\...` imports in the `Company` module (including Mappers).
- [x] Run manual tests/probes to verify that creating, updating, and listing companies works without 500 errors or white screens.
- [~] Verify that the `RedirectResponse` in `CompanyController` works as expected now that the rogue `exit()` is gone.

---

## Execution notes (2026-11-14)

Full report: `docs/ai-reports/company-infrastructure-refactoring.md`.
Decisions: `docs/ai-reports/DECISIONS.md`.

Steps 1–3 were executed as written. Two items in this plan did not match the code:

- **No rogue `exit()` exists.** `grep -rn "exit;\|exit(\|die("` over `core/Company/`,
  `CompanyController.php` and `views/company/` returns nothing, and
  `git log -S "exit"` on the controller returns no commit. The only `exit` in the
  write path is `RedirectResponse::send():169`, which is intentional and documented.
  The Step 4 checkbox stays partial because there was nothing to verify.
- **Step 2's duplicate-key hook had nothing to detect.** `companies.name` was
  declared `->index()`, not `->unique()`, so `CompanyDuplicateException` could never
  fire. Per the developer's decision the constraint was added as a migration
  (`2026_10_31_222317_make_companies_name_unique.php`, ported from an untracked
  Laravel original), making the hook live.

Two defects were introduced by the rewrite and fixed before completion; both are
detailed in the report. The full suite is red (32 errors, 3 failures) at HEAD and
byte-identical after the change; the pre-existing probe failures are documented
with evidence.
