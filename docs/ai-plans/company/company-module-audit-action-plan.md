# Action Plan: Company Module Full Audit & Gap Analysis

**Document ID:** `company/02-action-plan-company-module-audit.md`
**Role:** Software Architect & Domain Specialist
**Objective:** Conduct a comprehensive audit of the existing `Company` module to identify missing implementations, architectural violations, and incomplete features against the project's strict guidelines (AGENTS.md, Rules 01-06).

## Phase 1: Domain & Application Purity Audit
Verify that the core business logic is perfectly isolated and framework-agnostic.

**Execution Steps:**
1. **Entity Check (`CompanyEntity`):**
   - Is it a `readonly class` (not `final`)?
   - Does it enforce invariants through behavior methods (e.g., `create()`, `changeStatus()`)?
   - Is it free from anemic design (no simple getter/setter bags)?
   - Does it carry `$domainEvents` and `releaseEvents()` if it raises events?
2. **Services Check (`CompanyCommandService`, `CompanyQueryService`):**
   - **Command Service:** Does it use `TransactionManagerContract::run()` for every write? Are events dispatched *after* commit? Does it return a `Result` object?
   - **Query Service:** Is it strictly read-only? Does it use the `CompanyQueryContract` exclusively (no write port dependencies)?
3. **DTOs & Mappers:**
   - Do Request DTOs have immutable `with*()` methods for derived fields?
   - Does `CompanyMapperContract` take a plain array (not a FormRequest)?
   - Does `CompanyResponseMapper` handle all presentation concerns?

## Phase 2: Infrastructure & Persistence Audit
Verify that the adapters correctly bridge the core with WordPress without leaking framework details.

**Execution Steps:**
1. **Write Repository (`WpCompanyWriteRepository`):**
   - Does it extend `BaseWriteRepository` and implement `CompanyRepositoryContract`?
   - Are all writes prepared? Does it use `findByIdForUpdate()` for read-then-write locks?
2. **Read Repository (`WpCompanyReadRepository`):**
   - Does it extend `BaseReadRepository` and implement `CompanyQueryContract`? (Recently refactored, verify status).
3. **Entity Mapper (`CompanyEntityMapper`):**
   - Does it properly explicitly cast database string rows to Entity types?

## Phase 3: Delivery & Wiring Audit
Ensure the HTTP/Console layer is thin and the module is correctly bootstrapped.

**Execution Steps:**
1. **Controllers:**
   - Are they injecting Inbound Service Contracts (not concrete services)?
   - Do they use guard clauses on `$result->isFailure()`?
   - Are they free of business logic and database queries?
2. **Wiring:**
   - Are all ports bound exactly once in `CompanyServiceProvider`?
   - Is `CompanyServiceProvider` registered in `config/app.php`?
3. **Routes:**
   - Are the routes properly registered in `routes/web.php` or `routes/api.php`?
   - Are they protected by nonce and capability middleware?

## Phase 4: Gap Report Generation
Produce a final checklist of remaining tasks required to complete the `Company` module.

**Execution Steps:**
1. Cross-reference findings with the CRUD Pipeline (Rule 04) Definition of Done.
2. Compile a list of missing endpoints (e.g., Update, Delete), missing validation rules, or unimplemented domain events.
3. Write the findings to `docs/ai-reports/12-company-module-audit-report.md`.