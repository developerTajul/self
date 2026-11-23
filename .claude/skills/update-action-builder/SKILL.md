---
name: update-action-builder
description: Build, trace, and review the end-to-end update flow in Hexagonal Architecture (FormRequest, Request DTO and Mapper, Controller, Command Service with transaction and row lock, Entity behaviour, Repository, view). Enforces Result Pattern, rollback on every early return, file cleanup, events after commit, and the Rich Domain Model.
---

# Update Action Builder Skill

## Description

Build, trace, and review what happens end-to-end when a user clicks "Update" on an Update Form in this WordPress Hexagonal Architecture plugin: from the browser request through Middleware, Route, FormRequest, Controller, Mapper, Command Service (transaction, row lock, Entity behaviour), Repository (`$wpdb`), and back to the response. Use this skill whenever the developer asks to build, review, or debug an update flow for any module, whenever reviewing a Controller/Command Service/Repository trio for an update action, or whenever checking for the known bug classes (missing rollback on early return, TOCTOU race conditions, orphaned uploaded files, Result pattern misuse, events fired before commit, business rules leaking into the Service). Also use when asked to trace or explain "what happens when the update button is clicked."

> **This work follows the Rich Domain Model.** The Entity owns the business rules; the Command Service is an orchestrator only. Update is a **write** use case, so it lives in `{ModuleName}CommandService`, never in a Query Service. Read `AGENTS.md`, and load `.claude/rules/02-framework-adapter-wordpress.txt` (routes, DI, `$wpdb` and security rules), `04-crud-pipeline.txt`, `05-rich-domain-and-services.txt`, `06-ports-and-portability.txt`, and `08-events-and-queues.txt` before building or reviewing.

> **Autonomous runs:** when this skill is executed as a plan step (Autonomous Execution Mode), also follow `.claude/rules/09-autonomous_execution.txt`: put the verification checklist into the step report's Self-check table with evidence, write the step report, and record decisions in `docs/ai-reports/DECISIONS.md`. Where this skill says to stop and ask or tell the developer, apply 09 instead: additive Shared additions and the wiring files are in scope (A4) and are done and recorded, and only the hard stops in A10 halt the run.

**Placeholders used in this skill:**

| Placeholder    | Meaning                               | Example                        |
| -------------- | ------------------------------------- | ------------------------------ |
| `{ModuleName}` | PascalCase module name                | `Category`, `Product`, `Brand` |
| `{module}`     | lowercase/plural route & view segment | `categories`, `products`       |
| `{entity}`     | camelCase singular variable name      | `category`, `product`, `brand` |
| `{MODULE}`     | UPPER_SNAKE_CASE error-key prefix     | `CATEGORY`, `PRODUCT`, `BRAND` |

Use this skill for three purposes:

1. **Explaining/tracing** the full lifecycle when asked (e.g. "what happens when the update button is clicked").
2. **Reviewing** a new or existing Controller -> Command Service -> Repository trio against the Known Bug Watchlist below.
3. **Building** a new update flow, following the Build Guidance section.

When reviewing or building, do not change anything outside the request (AGENTS.md - STRICT SCOPE ADHERENCE). Older modules (single `{ModuleName}Service`, no Entity behaviour) keep their current shape unless the prompt asks to migrate them. Review them against the same bug classes and report the architecture gaps without refactoring.

## Quick Reference: Full Lifecycle (top to bottom)

```
Browser (Update button click, form submit)
   │
   ▼
HTTP Request (POST with a method/spoofed PUT, or PATCH) ──► Route in routes/web.php
   │                                                         (explicit Route::match/post inside the
   │                                                          existing admin group, ->name('{module}.update'))
   ▼
Middleware stack (auth, capability, nonce/CSRF, sanitize, trim, empty-strings-to-null)
   │
   ▼
FormRequest (e.g. UpdateCategoryRequest)
   — authorize() + validation rules run BEFORE the controller method executes
   │
   ▼
Controller::update(UpdateRequest $request)
   — thin, orchestration only; Service and Mapper constructor-injected
   — Mapper: plain array -> Request DTO ($this->mapper->mapToUpdateDTO($request->validated()))
   — calls ONE Command Service method, translates Result -> HTTP response (guard clause)
   │
   ▼
CommandService::update{ModuleName}()  ← orchestration only
   calls private executeInTransaction(operation: ..., logMessage: ..., fallbackMessageKey: ...,
                                      fallbackErrorCode: ..., context: [...])
   which wraps the operation in TransactionManagerContract::run(function () use (...) { ... }):
   1. findByIdForUpdate()   — locking read of the existing Entity
   2. derived fields        — slug via SlugGeneratorContract, new file via FileUploaderContract (DTO with*() clones)
   3. Entity behaviour      — business rules run INSIDE the Entity (it THROWS on a violation), returns a NEW Entity
   4. repository->update(entity) — persistence (write); events released BEFORE this call
   run() commits when the closure returns and rolls back when it throws. The catch INSIDE
   executeInTransaction() converts domain exceptions to Result::failure.
   5. after run() returns   — delete the old file, dispatch the released Domain Events
   │
   ▼
Wp{ModuleName}WriteRepository::update() / findByIdForUpdate()
   — `$wpdb`-facing; translates Entity ⇄ table array through the EntityMapper
   │
   ▼
BaseWriteRepository (findRecordForUpdate / write / insert / deleteRecord)
   — prepared `$wpdb` SQL; nulls written as the NULL keyword; duplicate keys translated
   │
   ▼
Database
   │
   ▼
Result (success/failure) bubbles back up through Command Service -> Controller
   │
   ▼
Controller returns RedirectResponse (redirect back with an 'error' or 'success' flash)
   │
   ▼
Browser re-renders (session flash message shown)
```

## Responsibilities & Known Bug Watchlist Per Layer

Apply the checklist below when reviewing each layer. These come from bugs actually found in real projects.

### 1. FormRequest and Route

- [ ] Validation rules cover presence and format only (`required`, `string`, `max`, `image`, `Rule::in(Enum::values())`). Business invariants (e.g. slug uniqueness) belong in the Entity/Command Service, not the FormRequest.
- [ ] `authorize()` checks the real capability (`current_user_can(...)` via the delivery layer), not a policy/gate.
- [ ] The route is declared in `routes/web.php` inside the existing admin group with an explicit `Route::match(...)`/`Route::post(...)` line and a `->name('{module}.update')`. WordPress has no `Route::resource()`; name the route, because a redirect to an undeclared name is a 404.
- [ ] The nonce and capability are verified on the write path (02 s7): a missing or invalid nonce is rejected before the Service runs.

### 2. Controller

- [ ] **Stays thin:** no business logic, no direct Repository or `$wpdb` access. Only Mapper -> Command Service -> Result-to-response.
- [ ] **Constructor injection:** the Command Service and `$mapper` (the `...MapperContract`) come through the constructor. `update()` receives only the FormRequest and the route id.
- [ ] The mapper accepts a plain `array` (`$request->validated()`), never the FormRequest.
- [ ] `Result::isFailure()` is checked with a **guard clause**, not a ternary, and both outcomes are handled.
- [ ] **NEVER uses try-catch blocks to catch Domain Exceptions.** The Service catches its own exceptions and returns `Result::failure`. The Controller only evaluates the returned `Result`.
- [ ] LSP check: the Controller does not violate the contract of any abstract base class/interface it implements.

### 3. Command Service (the most important section: most bugs are found here)

- [ ] **No leftover debug statements:** `dd()`, `dump()`, `var_dump()` anywhere.
- [ ] **Every write runs inside `TransactionManagerContract::run()`.** No manual `beginTransaction()`, `commit()`, or `rollback()` and no `DB::` facade in the Service. A write without `run()` is 🔴 Critical (AGENTS.md - MANDATORY TRANSACTION MANAGEMENT PROTOCOL).
- [ ] **Failure after a write is THROWN, not returned.** Inside the closure, a rule violation throws a domain exception so `run()` rolls back. A `Result::failure()` returned from inside the closure is allowed only for a guard that runs before any write (not-found). A Throwable swallowed inside the closure after a write makes `run()` COMMIT partial work (🔴 Critical).
- [ ] **One dedicated `catch` block per domain exception OUTSIDE `run()`**, each returning `Result::failure` with its own domain key. The generic `catch (\Throwable)` logs the exception object, removes a newly uploaded file, and returns a safe generic failure.
- [ ] **TOCTOU race condition: MANDATORY row-level locking.** The "fetch existing entity" step of `update*()` MUST use a locking read, never a plain read:
    - The Service calls `findByIdForUpdate($id)`, NOT `findById($id)`.
    - `findById()` for that step inside a transaction is a **🔴 Critical violation**: it takes no lock, so a concurrent request can change the row between read and write. A `UNIQUE` index protects only the slug; every other column (price, status, featured image) stays racy.
    - The module's `RepositoryContract` MUST declare `findByIdForUpdate(int $id): ?{ModuleName}Entity`, and the concrete `Wp{ModuleName}WriteRepository` MUST implement it through `BaseWriteRepository::findRecordForUpdate(int $id, string $table, string $action)`, which runs a prepared `SELECT * FROM ... WHERE id = %d LIMIT 1 FOR UPDATE`. Do not re-implement the lock in the module repository. Verify `findRecordForUpdate()` exists on `BaseWriteRepository`. If it does not, stop and tell the developer, because it is a Shared change.
    - The post-write refresh read (reloading the entity so it contains newly created child rows) MAY use plain `findById()`: the row is already locked at that point.
    - Test requirements for this lock are in `.claude/rules/07-testing.txt` (testing is paused; do not write or require tests).

- [ ] **Business rules live in the Entity.** Rules, state transitions, and derived business values run through an Entity behaviour method that returns a NEW Entity. The Service never reads entity fields to decide (🟡 Design concern). Slug generation that needs a uniqueness check goes through `SlugGeneratorContract` in the Service. The decision about whether a new slug is needed belongs to the Entity.
- [ ] **File cleanup is correct on every branch:**
    - If a new file was uploaded and any later step fails (repository update, domain exception, throwable), the newly uploaded file is deleted (orphan-file bug).
    - On success, the old file is deleted **after** `run()` returns (the transaction has committed). Deleting before the commit risks losing the old file if the transaction rolls back.
    - A failing file cleanup after a successful commit is logged, never turned into a failed update.
- [ ] **Events and jobs fire only after commit.** Domain Events are released from the Entity the behaviour method returned, BEFORE persisting (`$events = $updatedEntity->releaseEvents()`), never from the Entity the repository returns (rebuilt from storage, so its list is empty). They go through `EventDispatcherContract` after `commit()`, never before and never on rollback. A failing dispatch after commit is logged and the Result stays success. The Entity never dispatches its own events.
- [ ] **Result Pattern:** every Service method returns `Result` carrying a `{ModuleName}ResponseDTO` (never an Entity or Model). No exception leaks upward.
- [ ] **Domain error keys:** `errorCode` values are keys like `{MODULE}_NOT_FOUND`, `{MODULE}_SLUG_CONFLICT`, never raw HTTP statuses.
- [ ] **Duplicate failure messages/error codes** are DRY'd up through a helper method (e.g. one `removeFileQuietly(...)` cleanup helper and shared failure-key constants).
- [ ] The Service stays output-agnostic: it behaves the same whether called from Web, API, Console Command, or a WordPress handler.
- [ ] No framework import, facade, or helper in Core Domain/Application. Ports use the `...Contract` suffix.

### 4. Repository

- [ ] Repository only handles persistence + Entity mapping. No business rule (slug logic, thumbnail logic) has leaked in.
- [ ] It takes and returns **Entities**: `update(Entity): Entity`. It never accepts a DTO or loose array from the Service, and never returns a raw `$wpdb` row.
- [ ] `findById()` / `findByIdForUpdate()` return `null` when the record is missing (the Service decides based on null). Framework and driver failures are raised as a standard `RuntimeException`, never swallowed. Note `$wpdb` reports a failed statement by returning `false`/`null` and setting `last_error` rather than throwing — a failed read must be distinguished from "no such row" via `last_error`.
- [ ] Entity -> table array goes through `EntityMapper::toPersistenceArray()`; the reverse through `toEntity(array $row)`.
- [ ] Duplicate-key (UNIQUE index) violations are mapped to the module's domain conflict exception inside the adapter, by overriding `BaseWriteRepository::duplicateException()` (race-safe uniqueness, 04 STEP 2). `$wpdb` exposes no error code, so the check matches `last_error` text (SQLSTATE `23000` or MySQL's `Duplicate entry`).
- [ ] Every value is bound through `$wpdb->prepare()` or an `insert()`/format array. Table and column names are class constants, never request data.
- [ ] If a shared type like `PaginatedResult` is involved, it lives in `Shared\Domain` (avoids Dependency Rule violations). Check this when reviewing listing/index endpoints too.

### 5. BaseWriteRepository / BaseReadRepository

- [ ] `Wp{ModuleName}WriteRepository extends BaseWriteRepository` and `Wp{ModuleName}ReadRepository extends BaseReadRepository`. Neither extends the other; the read adapter inherits no write method, so the CQRS boundary holds by inheritance.
- [ ] The write adapter's `update()` calls `BaseWriteRepository::write(int $id, array $values, string $table, string $action)`. That helper builds the `SET` clause by hand **on purpose**: `$wpdb->update()` silently skips any column whose value is `null`, so a cleared nullable field would keep its old value while the caller was told the write succeeded. A null must compile to the `NULL` keyword.
- [ ] `findRecordForUpdate()` is `protected` and applies `FOR UPDATE`, like the other helpers (framework types must not leak upward). It returns the raw row, not an entity; the module adapter maps it.
- [ ] Table names are per-call suffix constants (`self::TABLE_SUFFIX`), never constructor state. The base ctor takes only `?wpdb $wpdb = null` and resolves the handle lazily, because providers build repositories during `register()` before `$wpdb` is loaded.
- [ ] No Eloquent anywhere: no `fill()`, `isDirty()`, `save()`, `refresh()`, `getAttributes()`, `toArray()`, no relationship or pivot calls, no `Illuminate\` import. Rows are `ARRAY_A` arrays.

### 6. View and Route Wiring

- [ ] **View Update Guard:** `views/{module}/edit.php` (plain PHP, not Blade) MUST post to the real update route and carry the record id, e.g. `<form method="post" action="<?php echo e(route('{module}.update')); ?>">` with a hidden `id` input and the nonce field. Use `e()` for every echoed value. Keep `enctype="multipart/form-data"` if and only if the schema has file/image columns. Remove any leftover "update route not defined yet" reminder.
- [ ] Error rendering uses the `!empty($errors['field'])` convention, and old input is read via `old('field')`.
- [ ] The view reads only ResponseDTO properties and contains no business rule.

## Build Guidance (when asked to implement the update flow)

Build only what is missing, in this order, and verify every signature against the codebase before writing:

1. **Route:** add an explicit `Route::match(['post', 'put'], '{module}/update', [Controller::class, 'update'])->name('{module}.update')` line inside the existing admin group in `routes/web.php`. Name it — a redirect to an undeclared name is a 404.
2. **FormRequest:** `{ModuleName}UpdateRequest`, format rules only, `authorize()` checking the capability.
3. **Request DTO:** `Application/DTOs/Requests/{ModuleName}UpdateDTO`: a `readonly class` with promoted properties only, **no static factories**, uploaded files typed `mixed`, and a `with*()` clone for each derived field (`withSlug`, `withStoreImage`/`withLogo`).
4. **Request Mapper:** add `mapToUpdateDTO(array $validatedData)` to `{ModuleName}MapperContract` and `{ModuleName}Mapper` (plain array, never a FormRequest).
5. **Entity:** add the behaviour method(s) the update needs. They enforce the rules, throw domain exceptions on violations, and return a new instance.
6. **Repository contract and adapter:** `findByIdForUpdate(int $id): ?Entity` on the WRITE port, implemented in `Wp{ModuleName}WriteRepository` via `BaseWriteRepository::findRecordForUpdate($id, self::TABLE_SUFFIX, $action)`, and `update(Entity): Entity` via `write($id, $values, self::TABLE_SUFFIX, $action)`.
7. **Command Service:** `update{ModuleName}(int $id, {ModuleName}UpdateDTO ${entity}UpdateDTO): Result`, delegating to the Service's private `executeInTransaction()` and shaped like the reference below.
8. **Controller:** append `update()`, using constructor-injected Service and `$mapper`, with a guard clause.
9. **View:** bind the form action as described in section 6.

Reference Command Service flow (verify the real `Result` signature, the Entity behaviour method name, and the port method names before using it). AGENTS.md §10 mandates the DRY form: the transaction boundary and the shared catch blocks live in ONE private `executeInTransaction()`, and every write use case delegates to it. Model it on the live `Core\Company\Application\Services\CompanyCommandService`:

```php
/**
 * Updates a {entity} inside a transaction, holding a row lock from the read
 * through to the save.
 *
 * @param Update{ModuleName}DTO $update{ModuleName}DTO
 *
 * @return Result Contains the {ModuleName}ResponseDTO on success.
 */
public function update{ModuleName}(Update{ModuleName}DTO $update{ModuleName}DTO): Result
{
    return $this->executeInTransaction(
        operation: function () use ($update{ModuleName}DTO): Result {
            ${entity}Entity = $this->{entity}Repository->findByIdForUpdate($update{ModuleName}DTO->id);

            if (${entity}Entity === null) {
                return $this->{entity}NotFound();
            }

            // Business rules run INSIDE the Entity, which guards its own
            // invariants and hands back a NEW instance (throwing on a violation).
            $updated{ModuleName} = ${entity}Entity->applyChanges(/* from the DTO */);

            // Domain Events are released from the Entity the behaviour method
            // returned, BEFORE persisting — the repository-returned entity is
            // rebuilt from storage, so its event list is empty.
            $events = $updated{ModuleName}->releaseEvents();

            $saved{ModuleName} = $this->{entity}Repository->update($updated{ModuleName});

            return Result::success(
                data: $this->responseMapper->toResponseDTO($saved{ModuleName}),
                message: $this->translator->translate(key: '{module}.updated'),
            );
        },
        logMessage: '{ModuleName} update failed.',
        fallbackMessageKey: '{module}.errors.update_failed',
        fallbackErrorCode: self::ERROR_UPDATE_FAILED,
        context: ['{entity}_id' => $update{ModuleName}DTO->id],
    );
}

/**
 * Runs a write use case inside the transaction boundary and converts its
 * failures into a Result. The callback throws on a violation after a write,
 * so a partial write is rolled back rather than committed.
 */
private function executeInTransaction(
    callable $operation,
    string $logMessage,
    string $fallbackMessageKey,
    string $fallbackErrorCode,
    array $context = [],
): Result {
    try {
        /** @var Result $result */
        $result = $this->transactionManager->run($operation);

        return $result;
    } catch ({ModuleName}InvalidException $exception) {
        // A rejected rule is bad input, not a system failure.
        return Result::failure(
            message: $this->translator->translate(key: $exception->getMessage()),
            errorCode: $exception->errorCode(),
        );
    } catch (\Throwable $exception) {
        $this->logger->error($logMessage, ['exception' => $exception] + $context);

        return Result::failure(
            message: $this->translator->translate(key: $fallbackMessageKey),
            errorCode: $fallbackErrorCode,
        );
    }
}
```

Notes that matter when you build this:

- **Core carries no user-facing text.** Messages go through `TranslatorContract` with keys (`'{module}.updated'`), never a hardcoded English string in the Service (AGENTS.md D5).
- **A `Result::failure()` returned from inside the closure is allowed only for a guard that runs before any write** (the not-found case above). A failure after a write must be THROWN, or `run()` would commit partial work.
- **File cleanup**, when the schema has an image/file column: keep the newly uploaded path in a `use (&$uploadedPath)` variable and delete it in the catch blocks; delete the replaced old file only after `run()` returns, so a rollback cannot lose it. Use one `removeFileQuietly()` helper, and log a failed cleanup rather than turning a committed update into a failure.
- **Events are dispatched after commit**, from the released list captured in the closure, never inside it and never on rollback.

## Review Workflow

1. Read files in this order: Controller -> Command Service -> Entity -> Write Repository -> BaseWriteRepository -> View.
2. Check each layer against its checklist above, paying special attention to the **Command Service's transaction/rollback, row-lock, file-cleanup, and event sections**, where most bugs are found.
3. Report findings by severity:
    - 🔴 **Critical** (data integrity/transaction bug): a write without `TransactionManagerContract::run()`, manual `beginTransaction()`/`commit()`/`rollback()` in the Service, a Throwable swallowed inside the closure after a write (`run()` would commit partial work), `findById()` instead of `findByIdForUpdate()` inside the transaction, domain exception falling through to the generic catch, orphaned or prematurely deleted file, events dispatched before commit, events released from the repository-returned Entity (always empty), a cleanup failure reported as a failed update.
    - 🟡 **Design concern:** business rule in the Service instead of the Entity, DRY violations, Entity/Model returned from the Service, ternary instead of guard clause, raw HTTP status in `errorCode`, framework import in Core.
4. Tests are paused (`AGENTS.md` - UNIT TESTING POLICY): do not review, write, or require them.
5. If the same bug class could plausibly occur in another module (Auth, Product, etc.), flag it and suggest adding it to this skill's Known Bug Watchlist.

## Notes

- This skill is project-specific: it assumes a Clean/Hexagonal Architecture (Domain/Application/Infrastructure layers, Rich Domain Model, Command/Query Service split, Result Pattern, `PaginatedResult` in `Shared\Domain`).
- When a new bug class is found, add it to the Known Bug Watchlist above and update this skill, so future reviews don't miss it again.
- Report only what you verified in the actual code. A review claim or checklist tick that the code does not support is a defect.
