---
name: edit-form-builder
description: Builds the Edit Form feature for a module (route, index link, controller edit action, Query Service read, repository findById, response mapper, edit.php view). Use when asked to add or implement the edit form for a module.
---

# Task: Implement `{ModuleName}` Edit Form Feature

Implement the `{ModuleName}` Edit Form feature based on the following specifications. **Ensure strict adherence to our Hexagonal Architecture.**

> **This work follows the Rich Domain Model.** Business rules live in the Entity, and Services are orchestrators only. The edit form is a **read** use case, so it goes through a Query Service. Read `AGENTS.md`, and load `.claude/rules/02-framework-adapter-wordpress.txt` (routing, nonce, `$wpdb` mapping), `05-rich-domain-and-services.txt`, and `06-ports-and-portability.txt` before writing code.

> **Autonomous runs:** when this skill is executed as a plan step (Autonomous Execution Mode), also follow `.claude/rules/09-autonomous-execution.txt`: put the verification checklist into the step report's Self-check table with evidence, write the step report, and record decisions in `docs/ai-reports/DECISIONS.md`. Where this skill says to stop and ask or tell the developer, apply 09 instead: additive Shared additions and the wiring files are in scope (A4) and are done and recorded, and only the hard stops in A10 halt the run.

> **On the code snippets below:** each one is a **reference implementation** showing the expected logic, structure, and step order — it is not a literal drop-in. Before writing any method, verify the exact signatures (`Result`, mapper methods, logger calls, etc.) and style conventions against **AGENTS.md** and the existing codebase. If the real signature or convention differs from the reference, follow the real one — never the placeholder.

> **Placeholders used in this template:**
> - `{ModuleName}` — PascalCase entity name (e.g. `Category`, `Product`, `Brand`)
> - `{module}` — lowercase/plural route & path segment (e.g. `categories`, `products`, `brands`)
> - `{entity}` — camelCase variable name (e.g. `category`, `product`, `brand`)
> - `{MODULE}` — UPPER_SNAKE_CASE error-key prefix (e.g. `CATEGORY`, `PRODUCT`, `BRAND`)
> - `{FieldList}` — the entity's editable fields (e.g. `name, slug, status`)

---

## Architecture Rules for This Task (read first)

1. **Query Service.** Loading the edit form is a read. It goes in `{ModuleName}QueryService`. No transaction, no events, no queue, no write call.
2. **Service is an orchestrator.** Load, map to a ResponseDTO, return. Do not put business rules in it.
3. **ResponseDTO only.** The Service returns `Result` carrying `{ModuleName}ResponseDTO`. The Controller and the view never receive an Entity or a storage row.
4. **Domain error keys.** `Result` `errorCode` holds domain keys (e.g. `{MODULE}_NOT_FOUND`), never raw HTTP statuses like `'404'`. The Controller translates keys to responses.
5. **Ports only.** Core code (Domain and Application) uses `...Contract` ports and imports nothing from Laravel or WordPress. `$wpdb` access stays in Infrastructure.
6. **Constructor injection.** The Controller gets its Service(s) and Mapper contract through the constructor. `edit()` takes only the route id.
7. **Legacy modules.** If `{ModuleName}` is an older module (single `{ModuleName}Service`, no `{ModuleName}QueryService`), add `get{ModuleName}ById` to the existing Service. Do not split it or migrate it unless the prompt asks. All other rules in this file still apply.

---

## Task 1: Routing

Ensure a `GET` route exists for the edit action. It is a **hidden admin screen**, and this shape
is not optional — the hide is what stops WordPress drawing a menu item:

| Key | Value |
|---|---|
| Route | `Route::get({ModuleName}Controller::EDIT_SLUG, ...)` |
| Name | `{module}.edit` |
| Controller Action | `{ModuleName}Controller@edit` |

```php
// Hidden: the screen is reached with an id on the query string, so it has
// no fixed link a menu entry could carry. Without this the route registers
// as a top-level menu of its own and WordPress draws an "Edit {module}"
// item that goes nowhere.
Route::get({ModuleName}Controller::EDIT_SLUG, [{ModuleName}Controller::class, 'edit'])
    ->name('{module}.edit')
    ->withOptions([
        'page_title' => 'Edit {module}',
        'menu_title' => 'Edit {module}',
        'capability' => {ModuleName}Controller::CAPABILITY,
        'hidden'     => true,
    ]);
```

Three things this shape encodes (`02-framework-adapter-wordpress.txt` s7):

- **`hidden => true` is load-bearing.** Omit it and the screen appears as its own top-level menu
  entry pointing at a URL that cannot render without an id.
- **The path comes from a class constant** (`EDIT_SLUG`), as the live `CompanyController` does, so
  the route path and the controller's own links cannot drift.
- **A get-screen carries no nonce** — it changes nothing. Only `POST` write routes carry
  `->middleware(['nonce:' . ...])`. The id arrives on the query string, which is why the screen
  has no fixed link.

Routes are declared with the `Route::` facade (`Framework\Support\Facades\Route`) inside the
existing admin group. Never call `register_rest_route()`/`add_menu_page()` directly in `routes/`
or a controller — the framework's `Route::` adapter is what calls them underneath.

Cross-check the live reference: `routes/web.php` (`CompanyController::EDIT_SLUG`) and
`app/Http/Controllers/Admin/CompanyController.php::edit()`.

If the module's resource is already registered with `->only([...])`, add `'edit'` to it (and `'update'` later with the update feature). If it uses `->except([...])`, remove `'edit'` from it.

---

## Task 2: Index View Update

**File:** `views/{module}/index.php` — plain PHP templates, **not** Blade. There is no
`resources/views/`, no `.blade.php`, and no template engine.

Update the "Edit" action in the index view table so it points at the edit screen with the row id.
The id travels on the **query string**, because the edit screen has no fixed link (Task 1):

```php
<?php // $editUrlFor($company->id) appends the id param to the screen URL. ?>
<a href="<?= e($editUrlFor($company->id)) ?>" class="button">Edit</a>
```

- The URL is built by a view closure (`$editUrlFor`) from variables the controller passes
  (`$editUrl`, `$editPageParam`, `$editPageValue`, `$editIdParam`) — do **not** hardcode a URL or
  a query-string key in the view.
- Escape every interpolated value with `e()`.
- `${entity}` in the index is the `{ModuleName}ResponseDTO` the index already receives. Do not change what the index passes to the view.

Cross-check the live reference: `views/company/index.php` (`$editUrlFor`).

---

## Task 3: Controller Implementation

**File:** `app/Http/Controllers/Admin/{ModuleName}Controller.php`

> **Namespace/Imports Note:** Ensure the controller imports `ResponseInterface` and `Result` correctly — do not invent or assume a namespace; verify it against the existing codebase.

Implement the `edit` method following the logic below (the code block is a reference — verify exact `Result`/return-type signatures against AGENTS.md and the codebase). The method must:

1. **Read the id from the request** through the controller's own `submittedId()`/`toPositiveInt()` guard (it arrives on the query string, not as a route parameter), and guard `null` before calling the Query Service.
2. **Delegate to the Query Service** — call `${entity}QueryService->get{ModuleName}ForEdit($id)`. The Controller must never talk to the Repository directly.
3. **Get dependencies through the constructor.** Add `{ModuleName}QueryServiceContract` to the Controller's constructor (constructor property promotion) if it is not already injected - the CONTRACT, never the concrete Service class. Do not inject it through the method.
4. **Check for failure** using `$result->isFailure()` with a guard clause. On failure, redirect to `{module}.index` with a flashed `error` message taken from `$result->message()` — do not construct a custom error message here.
5. **Read the data** with `$result->getData()` (a method), only after confirming success.
6. **Return the edit view** through the project's `view()` helper, passing the DTO's fields plus the form wiring the shared layout needs.
7. **Never catch exceptions here** — error handling belongs to the Service layer; the Controller only reacts to the `Result` object.

```php
public function edit(Request $request): ResponseInterface
{
    $id = $this->submittedId($request);

    if ($id === null) {
        return redirect()->route('{module}.index')->with('error', '{ModuleName} was not selected.');
    }

    $result = $this->{entity}QueryService->get{ModuleName}ForEdit($id);

    if ($result->isFailure()) {
        return redirect()->route('{module}.index')->with('error', $result->message());
    }

    ${entity} = $result->getData();

    return view('{module}.edit', [
        '{entity}' => [/* the DTO's fields, mapped to the view's expected keys */],
        ...$this->formWiring(self::UPDATE_ACTION, $this->editScreenUrl(${entity}->id)),
    ])->withTitle('Edit {module}');
}
```

---

## Task 4: Query Service Implementation

**File:** `{ModuleName}QueryService.php`

Implement the `get{ModuleName}ById` method following the logic below (the code block is a reference — verify `Result::failure`/`Result::success` signatures, mapper method name, and logger call format against AGENTS.md and the codebase). The method must:

1. **Fetch the entity** by calling `{entity}Repository->findById($id)`.
2. **Handle the not-found case:** if the repository returns `null`, return `Result::failure()` with the error key `{MODULE}_NOT_FOUND` and a message stating the `{ModuleName}` was not found for that ID. Do not throw an exception for this case — it is an expected outcome, not a system error.
3. **Map the entity to a response DTO** using `$this->responseMapper->toResponseDTO()`, so the Service never leaks Domain Entities to the Controller/View layer.
4. **Return success:** wrap the DTO in `Result::success()` with a clear success message.
5. **Catch unexpected errors:** wrap the whole flow in a try/catch. On `\Throwable`, log the error (with the exception object, not just the message string) via `$this->logger->error()`, then return a generic `Result::failure()` with its own error key (`{MODULE}_FETCH_ERROR`) — never expose raw exception details to the end user.
6. **Stay read-only.** No transaction manager, no event dispatcher, no queue, no repository write call.
7. **Never return the raw Entity or Model directly** — the only valid return type from this method is `Result`.

The Query Service constructor needs only the repository contract, the response mapper, and the logger (constructor property promotion). Do not inject `TransactionManagerContract`, `EventDispatcherContract`, `QueueManagerContract`, `SlugGeneratorContract`, or `FileUploaderContract` here.

```php
/**
 * Finds one {entity} for the edit form.
 */
public function get{ModuleName}ById(int $id): Result
{
    try {
        ${entity}Entity = $this->{entity}Repository->findById($id);

        if (${entity}Entity === null) {
            return Result::failure(
                message: "{ModuleName} not found with ID: {$id}",
                errorCode: '{MODULE}_NOT_FOUND',
            );
        }

        $responseDTO = $this->responseMapper->toResponseDTO(${entity}Entity);

        return Result::success(
            data: $responseDTO,
            message: "{ModuleName} retrieved successfully.",
        );
    } catch (\Throwable $exception) {
        $this->logger->error(
            "Failed to fetch {module} [ID: {$id}]: " . $exception->getMessage(),
            ['exception' => $exception],
        );

        return Result::failure(
            message: "We couldn't retrieve the {entity} right now. Please try again.",
            errorCode: '{MODULE}_FETCH_ERROR',
        );
    }
}
```

---

## Task 5: Repository Layer Implementation

**Files:** `{ModuleName}QueryContract.php` (read port, in `Application/Contracts/`) and
`Wp{ModuleName}ReadRepository.php` (adapter, in `Infrastructure/Repositories/`)

Declare `findById(int $id): ?{ModuleName}Entity` on the contract (only if it is not already declared), then implement it in the read adapter (the code block is a reference — verify the mapper's `toEntity` signature against AGENTS.md and the codebase). The method must:

1. **Fetch the raw row** through `$wpdb` in the read adapter — not Eloquent, not a Model. The adapter extends `BaseReadRepository` and owns its own `$wpdb` read query (`02-framework-adapter-wordpress.txt` s7); the Service never sees a row.
2. **Return `null` immediately** if no row is found — do not throw, do not return an empty Entity.
3. **Convert the raw row to a Domain Entity** via the EntityMapper (`$this->mapper->toEntity($row)`, taking the raw column values) — the Repository must never return a raw row/array to the Service layer.
4. **Return type must stay `?{ModuleName}Entity`** — nullable, never a bare Entity or a Result object (Result belongs to the Service layer, not the Repository).
5. **Catch storage failures** inside the adapter and rethrow them as a standard `RuntimeException`. Never swallow them silently (§11).
6. **Prepare every value.** No interpolation into SQL; use the base helpers or `$wpdb->prepare()`.

```php
public function findById(int $id): ?{ModuleName}Entity
{
    $row = $this->fetchRow($id); // adapter-private $wpdb read; prepared, never interpolated

    if ($row === null) {
        return null;
    }

    return $this->mapper->toEntity($row);
}
```

---

## Task 6: Response Mapper Implementation

**File:** `{ModuleName}ResponseMapper.php`

Ensure the `toResponseDTO` method in `{ModuleName}ResponseMapper` utilizes `StorageContract` to resolve any file/thumbnail URL fields, if applicable. Follow the exact method signature and mapping convention already defined in AGENTS.md / the existing codebase — do not invent a new mapper pattern.

- The mapper lives in the Application layer, so it depends on the `StorageContract` **port** only, never on a framework storage class or facade.
- If `StorageContract` has no concrete adapter yet, the Adapter Co-Generation Mandate applies: create the adapter under `core/Shared/Infrastructure/` and bind it once in the composition root (`AppServiceProvider`). Do not call `Storage::url()` or `asset()` inside the mapper.
- Add the resolved URL to the `{ModuleName}ResponseDTO` as a plain string. The ResponseDTO holds plain PHP types only.
- If the module has no file/image fields, leave the mapper untouched.

---

## Task 7: Create Edit View

**File:** `views/{module}/edit.php` — plain PHP, rendered through the project's `view()` helper
and wrapped by `views/layout.php` as `$content`. **No** Blade: no `@csrf`, no `@method`, no
`{{ }}`, no `resources/views/`.

Create the edit view file:

- Build a form pre-filled with the `${entity}` data (`{FieldList}`), and display any existing file/image URL fields. Read submitted values back through the project's `old('field', default)` helper so a rejected submission keeps what the user typed.
- Include the **nonce field** and the form `action` supplied by the controller's form wiring —
  there is no `@csrf` directive and no `PUT` method. A WordPress admin form posts via
  `admin-post.php`; the update route is a `POST` carrying the nonce.
- **Form Enctype:** If the module involves file/image upload, the form tag must explicitly include `enctype="multipart/form-data"`. Without it, upload requests will not work.
- **Form Action:** Do not invent one and do not leave `action="#"`. Pass the real action in from the controller's `formWiring(self::UPDATE_ACTION, ...)` and `$formAction` view variable, as the live `CompanyController::edit()` does. If the update route genuinely does not exist yet, leave a visible `<?php // TODO: point to the {module} update route when it is built. ?>` comment rather than a silent placeholder.
- Escape every output value with `e()`; render per-field errors through `field_error()`/`field_class()` (`view-error-rendering-convention`), not a hand-rolled `isset()`.
- The view reads only ResponseDTO properties. It never calls Entity methods and never contains a business rule.

Cross-check the live reference: `views/company/edit.php`.

---

## Task 8: Unit Tests (moved to rule 07 - paused)

Testing is paused (`AGENTS.md` - UNIT TESTING POLICY). Do not write or claim tests. Test requirements live in `.claude/rules/07-testing.txt`, which is loaded only when the developer asks for tests.

---

## Common Pitfalls Checklist (Anti-Hallucination / No-Shortcut Rules)

The agent must self-verify against every item below **by checking the actual generated code**, not from memory. Claim only what the code really contains. A false claim is a defect.

- [ ] **Code snippets in this file are reference implementations, not literal code.** Verify every signature and convention against AGENTS.md and the existing codebase before writing — AGENTS.md is the style authority, not this template.
- [ ] **No invented method signatures.** Every method called (`findById`, `toResponseDTO`, `Result::failure`, etc.) must match the exact signature already defined elsewhere in the codebase — never assume a signature; check first.
- [ ] **No invented namespaces/imports.** All `use` statements must be verified against the actual class locations, not guessed from naming convention.
- [ ] **Route declared as a hidden admin screen** (`Route::get(...EDIT_SLUG...)` with `->withOptions(['hidden' => true])`) inside the existing group. No `Route::resource()`, and no direct `register_rest_route()` call.
- [ ] **Edit is a read use case.** The logic is in the Query Service (or the existing single Service for legacy modules), with no transaction, event, queue, or write call.
- [ ] **Controller gets its Service through the constructor** and uses a guard clause on `Result->isFailure()`.
- [ ] **No skipped error handling.** The try/catch and `Result` failure paths must remain exactly as specified — no shortcuts that swallow exceptions or return raw models instead of `Result`.
- [ ] **`errorCode` values are domain keys** (`{MODULE}_...`), never raw HTTP statuses like `'404'`. The generic failure also has its own key.
- [ ] **No layer leakage.** Controller must not touch the Repository directly; Repository must not return raw Models; the Service must not return an Entity. Only ResponseDTOs reach the Controller and view.
- [ ] **No framework import or facade in Core Domain/Application.** Ports use the `...Contract` suffix, and the mapper uses `StorageContract`, not `Storage::` or `asset()`.
- [ ] **Form enctype present when file uploads exist.**
- [ ] **Placeholder route (`action="#"`) used only until the real update route exists** — flagged with a visible TODO, not left silently.
- [ ] **No fabricated field names.** Only use `{FieldList}` fields that actually exist on the entity/migration — verify against the schema before writing view/form code.
- [ ] **Nonce field present** in every edit form without exception; the form posts to the real `admin-post.php` action passed in by the controller, not `action="#"`.
- [ ] **The view is plain PHP** (`views/{module}/edit.php`), with `e()` escaping and the project's `old()`/`field_error()` helpers — no Blade, no `@csrf`, no `@method`, no `{{ }}`.
- Unit tests are paused (`AGENTS.md` - UNIT TESTING POLICY). Do not write or claim them.
- [ ] After adding files under a new namespace path, run `composer dump-autoload`.

---

## Deliverable

Output all updated and newly created files. End with the verification checklist above, filled in only from what the generated code actually contains.
