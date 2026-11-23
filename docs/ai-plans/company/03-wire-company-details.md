# STEP ID: Company/03

**MODULE:** Company

**GOAL:**
Wire up the Company Details (show) action to resolve audit findings F-001, G-001, G-003, D-001, D-002, and D-003.

**INPUTS:**

- `docs/ai-reports/12-company-module-audit-report.md`

**SKILL/RULES:**

- `04 STEP 5` (Thin Delivery)
- `05 R7` (Response DTOs)
- `AGENTS.md §8` (Service Layer)

**ACCEPTANCE:**

- [ ] `routes/web.php` registers a GET route for `companies/{id}` pointing to `[CompanyController::class, 'show']` with auth and capability middleware.
- [ ] `CompanyController::show(int $id)` is strictly typed, calls `CompanyQueryServiceContract::getCompanyDetails($id)`, and guards against `isFailure()` (redirecting to `companies.index` with an error message on failure).
- [ ] The controller passes the extracted `CompanyDetailResponseDTO` to `views/company/show.php`.
- [ ] `views/company/show.php` accesses data via object properties (e.g., `$company->name`) instead of array keys.

**OUT OF SCOPE:**

- Refactoring the edit URL (F-002) and implementing atomic bulk deletes (G-002).

**SCOPE FILES:**

- `app/Http/Controllers/Admin/CompanyController.php`
- `routes/web.php`
- `views/company/show.php`
