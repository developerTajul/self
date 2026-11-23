# Action Plan: Company Module REST API Implementation

**Document ID:** `06-action-plan-rest-api.md`
**Target Module:** `Company`
**Objective:** Implement the REST API delivery layer for the Company module, resolving audit finding G-004, strictly adhering to the Golden Rule of isolating HTTP concerns from the Service layer.

## Phase 1: Controller Implementation (Step ID: Company/06)

Create the API Controller to handle JSON requests and responses, ensuring strict DTO mapping.

**Execution Steps:**

1. **Create Controller:** Scaffold `app/Http/Controllers/Api/CompanyController.php`.
2. **Inject Services:** Inject `CompanyCommandServiceContract`, `CompanyQueryServiceContract`, and `CompanyResponseMapperContract` via constructor.
3. **Implement Endpoints (Strict Flow: `Request` $\rightarrow$ `FormRequest` $\rightarrow$ `DTO` $\rightarrow$ `Service`):**
   * `index(Request $request)`: Build a `CompanyQueryCriteria` object from the request. Never pass the raw `Request` to the Service.
   * `show(int $id)`: Maps to `getCompanyDetails($id)`.
   * `store(StoreCompanyRequest $request)`: Convert to DTO (e.g., `StoreCompanyDTO::fromRequest($request)`) before passing to `createCompany()`.
   * `update(UpdateCompanyRequest $request, int $id)`: Convert to DTO before passing to `updateCompany()`.
   * `destroy(int $id)`: Maps to `deleteCompany($id)`.
   * `bulkDestroy(BulkDestroyCompanyRequest $request)`: Validates an `ids` array in the body, then maps to `deleteMultiple($ids)`.
4. **Response Strategy:** 
   * Never build arrays manually inside the controller (e.g., `response()->json([...])`).
   * Strict Flow: `Service` $\rightarrow$ `Domain Entity` $\rightarrow$ `Mapper` $\rightarrow$ `ResponseDTO` $\rightarrow$ `JSON`.
   * Ensure all methods check `$result->isFailure()` and return standardized JSON responses using the DTOs.

## Phase 2: Route Registration (Step ID: Company/07)

Define the API endpoints and protect them with appropriate authentication and capability middleware.

**Execution Steps:**

1. **Update `routes/api.php`:**
   * Create a route group prefixed with `companies`.
   * Apply API authentication middleware and capability checks (`capability:manage_options`).
   * Register the standard RESTful routes:
     * `GET /companies` $\rightarrow$ `[Api\CompanyController::class, 'index']`
     * `GET /companies/{id}` $\rightarrow$ `[Api\CompanyController::class, 'show']`
     * `POST /companies` $\rightarrow$ `[Api\CompanyController::class, 'store']`
     * `PUT /companies/{id}` $\rightarrow$ `[Api\CompanyController::class, 'update']`
     * `DELETE /companies/{id}` $\rightarrow$ `[Api\CompanyController::class, 'destroy']`
     * `DELETE /companies` $\rightarrow$ `[Api\CompanyController::class, 'bulkDestroy']`

## Phase 3: Technical Enhancements (Enterprise Grade)

Incorporate advanced enterprise patterns to ensure scalability and robustness.

**Execution Steps:**

1. **Pagination Wrapper:** Ensure the `index` method does not return Eloquent's `LengthAwarePaginator`. Use a framework-agnostic `PaginatedResult<T>` class to wrap the list response.
2. **Idempotency & Global Handling:** Ensure `update` and `destroy` operations throw `CompanyNotFoundException` if the resource is not found. The global exception handler must catch this and map it strictly to an HTTP $404$ status.
3. **Complexity Analysis:**
   * Optimize the `index` method to run with a Time Complexity of $O(N \log N)$ or $O(N)$, depending on the page size.
   * Refactor the `bulkDestroy` persistence implementation to execute as a single `WHERE IN (...)` query (an $O(1)$ query operation) rather than iterating an $O(N)$ loop of individual delete statements. *(Note: This overrides the previous loop decision in Company/05)*.

## Phase 4: Wiring & Verification (Step ID: Company/08)

Ensure the new controller is properly wired and doesn't violate any architectural boundaries.

**Execution Steps:**

1. **Service Provider Check:** Verify container bindings in `CompanyServiceProvider`.
2. **T8 Purity Scan:** Ensure the API controller only interacts with permitted Core Contracts and doesn't leak `$wpdb` or domain logic.
3. **Linting:** Ensure `php -l` passes for all new/modified files.

## Acceptance Criteria

* [ ] `app/Http/Controllers/Api/CompanyController.php` exists and strictly returns JSON responses mapped via `ResponseDTO`.
* [ ] No raw `Request` objects are passed to the application services.
* [ ] `bulkDestroy` uses a dedicated `BulkDestroyCompanyRequest`.
* [ ] Framework-agnostic `PaginatedResult<T>` is utilized for listings.
* [ ] API routes are protected by capability/auth middleware in `routes/api.php`.