# STEP ID: Company/05

**MODULE:** Company

**GOAL:**
Implement atomic bulk deletes to resolve audit finding G-002.

**INPUTS:**

- `docs/ai-reports/12-company-module-audit-report.md`

**SKILL/RULES:**

- `AGENTS.md §10` (Transactions)
- `05 R5` (Command Service)

**ACCEPTANCE:**

- [ ] `CompanyCommandServiceContract` defines a new method: `deleteMultiple(array $companyIds): Result`.
- [ ] `CompanyCommandService::deleteMultiple()` implements the deletion of the provided company IDs inside a single `$this->executeInTransaction(...)` closure to guarantee atomicity.
- [ ] `CompanyController::bulkDestroy()` calls the new `deleteMultiple($ids)` method exactly once, entirely replacing the current foreach loop.

**OUT OF SCOPE:**

- REST API routes (G-004) and any changes to the UI/views.

**SCOPE FILES:**

- `core/Company/Application/Contracts/CompanyCommandServiceContract.php`
- `core/Company/Application/Services/CompanyCommandService.php`
- `app/Http/Controllers/Admin/CompanyController.php`
