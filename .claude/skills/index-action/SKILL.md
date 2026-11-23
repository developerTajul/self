---
name: index-action
description: Implements the paginated index (list) action for an existing module - Filter DTO and Filter value object, input-bounds Rules class, read-model projection, repository paginate(), Query Service method, GET index route, controller index(), and index view. Use when asked to add or implement the paginated index/list page for a module.
---

# Paginated Index Action Builder Skill (Data Retrieval Use-Case)

## Description

This skill safely implements the paginated data retrieval workflow (Index/GET) for an existing module. It adds the read-side objects (filter input, input bounds, projection), updates the repository contract and adapter, adds the Query Service method, and appends the `index()` method with its GET route, without destructively overwriting existing delivery-layer code.

> **This work follows the Rich Domain Model.** Business rules live in the Entity; Services are orchestrators only. The index is a **read** use case, so it goes through a Query Service and uses lightweight read models instead of hydrated Entities. Read `AGENTS.md`, and load `.claude/rules/02-framework-adapter-wordpress.txt` (routing, `$wpdb` mapping), `05-rich-domain-and-services.txt` (R12 read-side patterns), and `06-ports-and-portability.txt` before writing code.

> **Autonomous runs:** when this skill is executed as a plan step (Autonomous Execution Mode), also follow `.claude/rules/09-autonomous-execution.txt`: put the verification checklist into the step report's Self-check table with evidence, write the step report, and record decisions in `docs/ai-reports/DECISIONS.md`. Where this skill says to stop and ask or tell the developer, apply 09 instead: additive Shared additions and the wiring files are in scope (A4) and are done and recorded, and only the hard stops in A10 halt the run.

**Placeholders used in this skill:**

| Placeholder      | Meaning                               | Example                            |
| ---------------- | ------------------------------------- | ---------------------------------- |
| `{ModuleName}`   | PascalCase module name                | `Category`, `Product`, `Brand`     |
| `{ModulePlural}` | PascalCase plural for method names    | `Categories`, `Products`, `Brands` |
| `{module}`       | lowercase/plural route & view segment | `categories`, `products`, `brands` |
| `{entity}`       | camelCase singular variable name      | `category`, `product`, `brand`     |
| `{MODULE}`       | UPPER_SNAKE_CASE error-key prefix     | `CATEGORY`, `PRODUCT`, `BRAND`     |

## Context & Rules

- **Query Service, read-only:** The list logic goes in `{ModuleName}QueryService`. No transaction manager, event dispatcher, queue, or repository write call. The constructor needs only the repository contract, the response mapper, and the logger (constructor property promotion). Append `TranslatorContract` only when the method produces user-facing messages (05 R12.6).
- **One repository contract:** reads and writes live in the module's single `{ModuleName}RepositoryContract` (lean port). Append the new `paginate()` method to it. Do not create a separate read contract.
- **Legacy modules:** If the module is an older one (single `{ModuleName}Service`, no `{ModuleName}QueryService`, Entity-based listing), append the method to the existing Service and keep its current listing shape unless the prompt asks to migrate it. All other rules still apply.
- **Non-Destructive Policy (Safe Append):** You MUST NOT overwrite, modify, or delete existing methods, routes, views, or injected dependencies. You only APPEND. If the Controller does not yet receive the Query Service or the `{ModuleName}MapperContract`, append each as ONE new promoted parameter at the end of its existing constructor. Never reorder or remove existing parameters, and list each addition in your report.
- **Thin Controller:** it passes the query-string array to the Request Mapper, calls the Query Service, unpacks the `Result`, and returns a view. It holds no bound numbers (no `min(..., 100)`) and never calls the Repository.
- **Pagination boundary (STRICT):** no framework paginator — and no storage row — may leak into the Domain or Application layers. The adapter converts its own `$wpdb` result into a pure `Core\Shared\Domain\DataObjects\PaginatedResult`. Verify the real constructor and property names of `PaginatedResult` before using them.
- **Filters are defined by the plan, not invented.** Add a filter field (search text, status, ...) only when the step brief or the schema-driven plan names it. By default the Filter carries only `perPage`.
- **Domain error keys:** `Result` `errorCode` holds domain keys (e.g. `{MODULE}_LIST_ERROR`), never raw HTTP statuses.
- **Core stays framework-free:** No `Illuminate\*` import, facade, or helper in Core Domain/Application. Ports use the `...Contract` suffix.
- **PENDING ROUTES GUARD:** When scaffolding `index.blade.php`, use `#` for the `href` of every action link (Edit, Show, Delete) by default. Never use `route()` for those actions unless you have verified in `routes/web.php` that the route exists. Put a visible TODO next to each placeholder.

## Execution Steps

1. **Domain objects (`Core\{ModuleName}\Domain\`):** create only what does not exist yet. Pure PHP, `readonly`, no framework.
    - `Rules/{ModuleName}Rules.php` — a `final class` with constants `DEFAULT_PER_PAGE`, `MIN_PER_PAGE`, `MAX_PER_PAGE` and a pure `clampPerPage(int $perPage): int`. Values at or below zero fall back to the default; values above the maximum are cut to the maximum. This protects against huge `per_page` values (DoS / memory exhaustion).
    - `ValueObjects/{ModuleName}Filter.php` — an immutable Value Object with `perPage` (plus only the filter fields the plan names).
    - `ReadModels/{ModuleName}ProjectionDTO.php` — a `readonly` read model of plain types holding only what the list view needs. It lives in Domain because the repository contract returns it.
    - Append `public function paginate({ModuleName}Filter $filter): PaginatedResult;` to `{ModuleName}RepositoryContract` (import `Core\Shared\Domain\DataObjects\PaginatedResult`). The returned items are projections.

2. **Application input (`Core\{ModuleName}\Application\`):**
    - `DTOs/Requests/{ModuleName}FilterDTO.php` — `readonly`, promoted properties only, NO static factories. Typically `perPage` plus the plan's filter fields.
    - Append `mapToFilterDTO(array $validatedData): {ModuleName}FilterDTO` to `{ModuleName}MapperContract` and `{ModuleName}Mapper` (plain array in, never a framework request). The Mapper clamps with `{ModuleName}Rules::clampPerPage()`, so every channel (Web, API, CLI) gets the same bounds. Create the contract and mapper first if they do not exist yet, and bind them once in `AppServiceProvider`.
    - Append `toResponseDTOFromProjection({ModuleName}ProjectionDTO): {ModuleName}ResponseDTO` to `{ModuleName}ResponseMapper`.

3. **Infrastructure Repository (`Core\{ModuleName}\Infrastructure\`):**
    - Add `toProjection(array $row): {ModuleName}ProjectionDTO` to `{ModuleName}EntityMapper`. Explicit type conversions, never implicit casts: `$wpdb` hands back strings for numeric columns, so cast deliberately.
    - Implement `paginate()` in `Wp{ModuleName}ReadRepository`, which **extends `BaseReadRepository`**. This project has no Eloquent and no paginator object: the adapter runs its own prepared `$wpdb` query, computes the offset itself, and returns `PaginatedResult::fromSlice(...)`.
    - **Clamp a page past the end before computing the offset.** The count settles the real bound; without the clamp the read selects past the end and the pager shows a blank screen under a count that does not admit the page. `PaginatedResult::fromSlice()` owns that arithmetic — ask it (`isBeyondLastPage()`, `lastPage()`), do not re-derive the bounds.
    - `LIMIT`/`OFFSET` are ints this class computed from a validated page size; `%d` is left unquoted by the adapter's `prepare()`, which is what MySQL requires.
    - Return a `PaginatedResult` of projections. Catch storage failures inside the adapter and rethrow a standard `RuntimeException`.

```php
/**
 * Gets one page of {module} as read models.
 */
public function paginate({ModuleName}Filter $filter): PaginatedResult
{
    $db = $this->db();
    $table = $this->escapeIdentifier($this->tableName(self::TABLE_SUFFIX));

    $perPage = $filter->perPage;
    $total = (int) $db->get_var(sprintf('SELECT COUNT(*) FROM `%s`', $table));

    $requested = PaginatedResult::fromSlice(
        items: [],
        total: $total,
        perPage: $perPage,
        currentPage: $filter->page
    );

    $page = $requested->isBeyondLastPage() ? $requested->lastPage() : $requested->currentPage();
    $offset = ($page - 1) * $perPage;

    $rows = $this->rows(
        $db->prepare(
            sprintf(
                'SELECT `%s` FROM `%s` ORDER BY `%s` %s, `id` DESC LIMIT %%d OFFSET %%d',
                implode('`, `', self::COLUMNS),
                $table,
                self::ORDER_COLUMN,
                self::ORDER_DIRECTION
            ),
            $perPage,
            $offset
        ),
        'read the {module} list'
    );

    return PaginatedResult::fromSlice(
        items: array_map(fn (array $row): {ModuleName}ProjectionDTO => $this->toProjection($row), $rows),
        total: $total,
        perPage: $perPage,
        currentPage: $page
    );
}
```

Cross-check the live reference: `core/Company/Infrastructure/Repositories/WpCompanyReadRepository::getFilteredCompanies()`.

```php
/**
 * Gets one page of {module} as read models.
 */
public function paginate({ModuleName}Filter $filter): PaginatedResult
{
    $db = $this->db();
    $table = $this->escapeIdentifier($this->tableName(self::TABLE_SUFFIX));

    $perPage = $filter->perPage;
    $total = (int) $db->get_var(sprintf('SELECT COUNT(*) FROM `%s`', $table));

    $requested = PaginatedResult::fromSlice(
        items: [],
        total: $total,
        perPage: $perPage,
        currentPage: $filter->page
    );

    $page = $requested->isBeyondLastPage() ? $requested->lastPage() : $requested->currentPage();
    $offset = ($page - 1) * $perPage;

    $rows = $this->rows(
        $db->prepare(
            sprintf(
                'SELECT `%s` FROM `%s` ORDER BY `%s` %s, `id` DESC LIMIT %%d OFFSET %%d',
                implode('`, `', self::COLUMNS),
                $table,
                self::ORDER_COLUMN,
                self::ORDER_DIRECTION
            ),
            $perPage,
            $offset
        ),
        'read the {module} list'
    );

    return PaginatedResult::fromSlice(
        items: array_map(fn (array $row): {ModuleName}ProjectionDTO => $this->toProjection($row), $rows),
        total: $total,
        perPage: $perPage,
        currentPage: $page
    );
}
```

4. **Query Service (`{ModuleName}QueryService`):**
    - Append `get{ModulePlural}({ModuleName}FilterDTO $filterDTO): Result`.
    - Build the Domain `{ModuleName}Filter` from the DTO (Domain never receives an Application DTO), then call the repository's `paginate()`.
    - Map each projection to a `{ModuleName}ResponseDTO` through the ResponseMapper, rebuild the `PaginatedResult` with the mapped items and the same metadata (use the `PaginatedResult`'s own mapping method if it has one), and return `Result::success(...)`.
    - Wrap in `try`/`catch (\Throwable)`. Log with `$this->logger->error()` (include the exception object), return a generic `Result::failure()` with `{MODULE}_LIST_ERROR`. Never expose the raw message.
    - Stay read-only: no transaction, event, or write call.

```php
/**
 * Gets one page of {module} for the index page.
 */
public function get{ModulePlural}({ModuleName}FilterDTO $filterDTO): Result
{
    try {
        $filter = new {ModuleName}Filter(perPage: $filterDTO->perPage);

        $paginatedProjections = $this->{entity}Repository->paginate($filter);

        $paginatedResponse = new PaginatedResult(
            items: array_map(
                fn ({ModuleName}ProjectionDTO $projection) => $this->responseMapper->toResponseDTOFromProjection($projection),
                $paginatedProjections->items,
            ),
            total: $paginatedProjections->total,
            currentPage: $paginatedProjections->currentPage,
            perPage: $paginatedProjections->perPage,
            lastPage: $paginatedProjections->lastPage,
        );

        return Result::success(data: $paginatedResponse, message: "{ModulePlural} retrieved successfully.");
    } catch (\Throwable $exception) {
        $this->logger->error(
            "Failed to list {module}: " . $exception->getMessage(),
            ['exception' => $exception],
        );

        return Result::failure(
            message: "We couldn't load the {module} right now. Please try again.",
            errorCode: '{MODULE}_LIST_ERROR',
        );
    }
}
```

    Verify the real `Result` signatures, mapper method names, and the `PaginatedResult` constructor against the codebase. Message literals above are for readability: when the module localizes text, use `TranslatorContract` (05 R12.6).

5. **Safe Route Injection (`routes/web.php`):**
    - The index is the module's **top-level admin screen**, declared with the `Route::` facade (`Framework\Support\Facades\Route`) inside the existing admin group. Never call `register_rest_route()`/`add_menu_page()` directly.
    - The path comes from a class constant (`SCREEN_SLUG`), so the route path and the controller's own links cannot drift.
    - A `GET` screen carries **no nonce** — it changes nothing. Only `POST` write routes get `->middleware(['nonce:' . ...])`.

```php
Route::get({ModuleName}Controller::SCREEN_SLUG, [{ModuleName}Controller::class, 'index'])
    ->name('{module}.index')
    ->withOptions([
        'page_title' => '{ModulePlural}',
        'menu_title' => '{ModulePlural}',
        'capability' => {ModuleName}Controller::CAPABILITY,
        'icon'       => 'dashicons-{icon}',
        'position'   => {position},
    ]);
```

Cross-check the live reference: `routes/web.php` (`CompanyController::SCREEN_SLUG`).

6. **Safe Controller Injection (`App\Http\Controllers\Admin\{ModuleName}Controller`):**
    - Append the `index(Request $request): View` method. Do not touch existing methods.
    - Build the filter with `$this->mapper->mapToFilterDTO($request->query())`. The Mapper clamps `per_page`, so the Controller contains no numeric bounds.
    - Call the Query Service, use a guard clause on `$result->isFailure()` (passing an empty `PaginatedResult`), and return the view.
    - Never catch exceptions here and never call the Repository.

7. **Index View (`resources/views/backend/pages/{module}/index.blade.php`):**
    - Create it only if it does not exist. If it exists, do not overwrite it. Report what would need adding instead.
    - Render a table from `${module}` (a list of `{ModuleName}ResponseDTO`). Show only ResponseDTO properties that exist. Never call Entity methods.
    - Action links (Edit, Show, Delete) follow the PENDING ROUTES GUARD: `href="#"` with a visible TODO.
    - Show `$error` when it is set.
    - `PaginatedResult` is framework-free, so Laravel's `->links()` is not available. Build the pagination controls from its metadata, keeping the other query values:

```blade
@if ($pagination->lastPage > 1)
    <nav aria-label="Pagination">
        @if ($pagination->currentPage > 1)
            <a href="{{ request()->fullUrlWithQuery(['page' => $pagination->currentPage - 1]) }}">Previous</a>
        @endif

        <span>Page {{ $pagination->currentPage }} of {{ $pagination->lastPage }}</span>

        @if ($pagination->currentPage < $pagination->lastPage)
            <a href="{{ request()->fullUrlWithQuery(['page' => $pagination->currentPage + 1]) }}">Next</a>
        @endif
    </nav>
@endif
```

## Unit Tests (moved to rule 07 - paused)

Testing is paused (`AGENTS.md` - UNIT TESTING POLICY). Do not write or claim tests. Test requirements live in `.claude/rules/07-testing.txt`, which is loaded only when the developer asks for tests.

## Verification Checklist (fill in only from what the generated code actually contains)

Claim only what you can point to in the code. A false claim is a defect.

- [ ] No existing method, route, view, or dependency was overwritten, reordered, or removed. Each constructor addition is a single appended parameter, listed in the report.
- [ ] The list logic is in the Query Service (or the existing single Service for legacy modules), with no transaction, event, queue, or write call.
- [ ] `{ModuleName}Rules` holds the bounds and `clampPerPage()`; the Request Mapper applies it; no Controller or Service contains a hardcoded bound number.
- [ ] `{ModuleName}FilterDTO` has no static factory; the Domain `{ModuleName}Filter` is what the repository contract receives (no Application type in Domain).
- [ ] The repository returns projections (`{ModuleName}ProjectionDTO`) inside a `PaginatedResult`, not Entities or storage rows, and no framework paginator appears in Domain or Application code.
- [ ] The repository maps rows with `array_map()` over `$wpdb` result rows, not a framework Collection, and never assumes a numeric column arrived as an int.
- [ ] The adapter clamps a page past the end through `PaginatedResult` before computing the offset.
- [ ] The Query Service returns `{ModuleName}ResponseDTO` items inside `Result`; `errorCode` is a domain key (`{MODULE}_LIST_ERROR`).
- [ ] Reads and writes share the one `{ModuleName}RepositoryContract` (no separate read contract).
- [ ] Route is the module's top-level admin screen declared with `Route::get(...SCREEN_SLUG...)` inside the existing group, with no `Route::resource()` and no direct `register_rest_route()` call.
- [ ] Controller uses a guard clause on `isFailure()` and never touches the Repository.
- [ ] No framework import or facade in Core Domain/Application; ports use the `...Contract` suffix.
- [ ] Index view uses `#` with a visible TODO for unverified action links, and pagination controls are built from `PaginatedResult`.
- [ ] Namespaces, imports, and signatures (`Result`, `PaginatedResult`, mappers) were verified against the codebase, not guessed.
- Unit tests are paused (`AGENTS.md` - UNIT TESTING POLICY). Do not write or claim them.
- [ ] After adding files under a new namespace path, run `composer dump-autoload`.

## Output

Deliver the generated code components cleanly. You MUST wrap each code component inside standard markdown code blocks (using backticks with the language, such as `php` and `blade`) to strictly preserve line breaks, indentation, and formatting. Keep conversational text to a minimum, and end with the filled-in verification checklist.

## Canonical Controller Pattern

The example uses the Category module. Replace names with the module's real ones, and verify imports against the codebase.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Core\Category\Application\Contracts\CategoryMapperContract;
use Core\Category\Application\Contracts\CategoryQueryServiceContract;
use Core\Shared\Domain\DataObjects\PaginatedResult;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class CategoryController extends Controller
{
    // Existing constructor and methods remain untouched. If CategoryQueryServiceContract
    // or CategoryMapperContract is not injected yet, append ONE promoted parameter each
    // at the end of the constructor (inject the CONTRACT, never the concrete Service):
    //   private readonly CategoryQueryServiceContract $categoryQueryService
    //   private readonly CategoryMapperContract $mapper

    /**
     * Shows the paginated category list.
     */
    public function index(Request $request): View
    {
        $categoryFilterDTO = $this->mapper->mapToFilterDTO($request->query());

        $result = $this->categoryQueryService->getCategories($categoryFilterDTO);

        if ($result->isFailure()) {
            return view('backend.pages.categories.index', [
                'categories' => [],
                'pagination' => new PaginatedResult(
                    items: [],
                    total: 0,
                    currentPage: 1,
                    perPage: $categoryFilterDTO->perPage,
                    lastPage: 1,
                ),
                'error' => $result->message,
            ]);
        }

        /** @var PaginatedResult $paginatedCategories */
        $paginatedCategories = $result->data;

        return view('backend.pages.categories.index', [
            'categories' => $paginatedCategories->items,
            'pagination' => $paginatedCategories,
        ]);
    }
}
```
