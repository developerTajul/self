# Task: Implement `{ModuleName}` Delete Feature

Implement the `{ModuleName}` Delete feature based on the following specifications. Ensure strict adherence to our Hexagonal Architecture.

> **This work follows the Rich Domain Model.** Business rules (for example "can this record be deleted?") live in the Entity, not in the Service. The Service is an orchestrator only. Read `AGENTS.md`, and load `.claude/rules/02-framework-adapter-wordpress.txt` (routing, nonce, `$wpdb`), `05-rich-domain-and-services.txt`, `06-ports-and-portability.txt`, and `08-events-and-queues.txt` before writing code.

> **Autonomous runs:** when this skill is executed as a plan step (Autonomous Execution Mode), also follow `.claude/rules/09-autonomous_execution.txt`: put the verification checklist into the step report's Self-check table with evidence, write the step report, and record decisions in `docs/ai-reports/DECISIONS.md`. Where this skill says to stop and ask or tell the developer, apply 09 instead: additive Shared additions and the wiring files are in scope (A4) and are done and recorded, and only the hard stops in A10 halt the run.

> **On the code snippets below:** each one is a _reference implementation_ showing the expected logic, structure, and step order — it is **not** a literal drop-in. Before writing any method, verify the exact signatures (`Result`, mapper methods, logger calls, etc.) and style conventions against `AGENTS.md` and the existing codebase. If the real signature or convention differs from the reference, follow the real one — never the placeholder.

**Placeholders used in this template:**

| Placeholder    | Meaning                               | Example                            |
| -------------- | ------------------------------------- | ---------------------------------- |
| `{ModuleName}` | PascalCase entity name                | `Category`, `Product`, `Brand`     |
| `{module}`     | lowercase/plural route & path segment | `categories`, `products`, `brands` |
| `{entity}`     | camelCase variable name               | `category`, `product`, `brand`     |
| `{MODULE}`     | UPPER_SNAKE_CASE error-key prefix     | `CATEGORY`, `PRODUCT`, `BRAND`     |

---

## Architecture Rules for This Task (read first)

1. **Rich Domain Model.** The Entity owns every business rule. If deleting is conditional (in use, has active children, wrong status), that check is a method on the Entity. The Service never reads entity fields to decide.
2. **Command Service.** Delete is a write use case. It goes in `{ModuleName}CommandService`, never in a Query Service.
3. **Service is an orchestrator.** It loads the Entity, asks the Entity, persists through the repository port, then handles side effects (file cleanup, events).
4. **Entity-based repository.** The repository receives the Entity, not loose arrays or data objects.
5. **Ports only.** Core code (Domain and Application) uses `...Contract` ports (`FileUploaderContract`, `EventDispatcherContract`, `TransactionManagerContract`) and imports nothing from Laravel or WordPress.
6. **Domain error keys.** `Result` `errorCode` holds domain-specific keys (e.g. `{MODULE}_NOT_FOUND`), never raw HTTP statuses like `'404'`. The Controller translates keys to responses.
7. **Legacy modules.** If `{ModuleName}` is an older module (single `{ModuleName}Service`, no `{ModuleName}CommandService`, no Entity behaviour), add the delete method to the existing Service and skip the Entity-behaviour and event steps unless the prompt explicitly asks to migrate it. Never refactor beyond the task. All other rules in this file still apply.

---

## Task 1: Routing

Deletion is a **web** route in `routes/web.php`, not a REST endpoint: it is submitted by an
admin screen form and must end in a redirect, not a JSON body. Routes are declared with the
`Route::` facade (`Framework\Support\Facades\Route`) inside the existing admin group — the
framework's `Route::` adapter is what calls `register_rest_route()`/`add_menu_page()` underneath,
so never call those WordPress functions directly in `routes/` or a controller.

| Key               | Value                            |
| ----------------- | -------------------------------- |
| Route             | `POST {module}/delete`           |
| Name              | `{module}.destroy`               |
| Controller Action | `{ModuleName}Controller@destroy` |

```php
Route::post('{module}/delete', [{ModuleName}Controller::class, 'destroy'])
    ->name('{module}.destroy')
    ->withOptions(['action' => {ModuleName}Controller::DESTROY_ACTION])
    ->middleware(['nonce:' . {ModuleName}Controller::DESTROY_ACTION]);
```

Three things this shape encodes, all mandatory (`02-framework-adapter-wordpress.txt` s7):

- **`POST`, never `DELETE` or `GET`.** WordPress's admin form path submits via `admin-post.php`,
  which supports only `GET`/`POST`; and a state-changing `GET` (a bare delete link) is a real
  vulnerability, not a style choice.
- **A nonce, named from a class constant.** `DESTROY_ACTION` is a `public const` on the
  controller (`public const DESTROY_ACTION = 'company_destroy';` — public because `routes/web.php`
  references it); the same constant is used for the form's nonce field, so the two cannot drift. A
  delete route without `nonce:` must not be merged.
- **A capability.** The surrounding `Route::group()` already carries
  `->middleware(['auth', 'capability:...'])`; a new destroy route goes inside that group rather
  than repeating the middleware.

Cross-check the live reference: `routes/web.php` (`companies/delete`) and
`app/Http/Controllers/Admin/CompanyController.php::destroy()`.

---

## Task 2: Index View Update (Frontend Confirmation)

**File:** `views/{module}/index.php` — plain PHP templates, **not** Blade. There is no
`resources/views/`, no `.blade.php`, no `@csrf`/`@method` directive, and no template engine: a
view is a `.php` file with `<?= e($value) ?>` for output escaping.

Delete must **not** be a plain `<a href>` — that is a state-changing `GET`. It must be a `<form>`
`POST` carrying the nonce field and the row id.

**One structural trap, and the reason the reference looks unusual:** the index table is already
wrapped in the bulk-actions `<form>` that posts to `admin-post.php`, and **nested forms are
illegal HTML**. So the per-row delete button cannot be its own `<form>` inside the table. Name a
form rendered *below* the table and point the button at it with HTML5's `form` attribute:

```php
<?php // Inside the row: a button that names a form of its own, rendered below the table. ?>
<button type="submit"
        form="<?= e($deleteFormIdFor($company->id)) ?>"
        class="button-link submitdelete"
        aria-label="Delete <?= e($company->name) ?>">Delete</button>
```

```php
<?php // Below the table: one small POST form per row, each carrying that row's id. ?>
<form id="<?= e($deleteFormIdFor($company->id)) ?>" method="post" action="<?= e($deleteFormAction) ?>">
    <?= $deleteNonceField ?>
    <input type="hidden" name="<?= e($deleteIdParam) ?>" value="<?= e((string) $company->id) ?>">
</form>
```

- **One form per row.** A single shared form would submit only the last rendered id.
- The nonce field and the form action are passed in as view variables (`$deleteNonceField`,
  `$deleteFormAction`, `$deleteIdParam`) by the controller — do not hardcode a nonce name or URL
  in the view; read them from the DTO/variables the index already receives.
- A browser that ignores the `form` attribute submits the *bulk* form instead, which is the safe
  failure direction (it carries the bulk nonce), never an unnonced delete.

**Confirmation popup:** the confirmation is the screen's own behaviour, not a required custom
modal. If the module wants one, use a plain `onsubmit` confirm on the row form; do **not**
introduce Alpine.js/jQuery — this project ships no JS framework.

---

## Task 3: Controller Implementation

**File:** `app/Http/Controllers/Admin/{ModuleName}Controller.php` (admin screens live under the
`Admin\` sub-namespace; a REST controller would live under `Api\`).

> **Namespace/Imports note:** ensure the controller imports `ResponseInterface` and `Result` correctly — do not invent or assume a namespace; verify it against the existing codebase.

Implement the `destroy` method following the logic below. The method must:

- **Read the submitted id from the request** (it is a `POST` body field, not a route parameter —
  a web delete route has no `{id}` path segment) through the controller's own
  `submittedId()`/`toPositiveInt()` guard, so a missing or non-numeric id cannot reach the
  Service.
- **Delegate to the Command Service** — call `${entity}CommandService->delete{ModuleName}($id)`, injecting `{ModuleName}CommandServiceContract` (the CONTRACT, never the concrete Service class) via constructor property promotion.
- **Handle the `Result`** — redirect to `{module}.index`, flashing `error` on
  `$result->isFailure()` and `notice` on success. The key is `notice`, not `success`: the layout
  renders `notice` for a non-failing badge, and `success` is not read.
- **`Result::message()` is a method call**, not a property.
- **Use a guard clause**, not a ternary.
- **Never catch exceptions here** — error handling belongs to the Service layer; the Controller only reacts to the `Result` object.

```php
public function destroy(Request $request): ResponseInterface
{
    $id = $this->submittedId($request);

    if ($id === null) {
        return redirect()->route('{module}.index')->with('error', '{ModuleName} was not selected.');
    }

    $result = $this->{entity}CommandService->delete{ModuleName}($id);

    if ($result->isFailure()) {
        return redirect()->route('{module}.index')->with('error', $result->message());
    }

    return redirect()->route('{module}.index')->with('notice', $result->message());
}
```

---

## Task 4: Domain Layer (Entity Behaviour, Exception, Event)

**Files:**

- `core/{ModuleName}/Domain/Entities/{ModuleName}Entity.php`
- `core/{ModuleName}/Domain/Exceptions/{ModuleName}CannotBeDeletedException.php`
- `core/{ModuleName}/Domain/Events/{ModuleName}DeletedEvent.php` (only if other parts of the system react to deletion)

**When to add what (no speculative rules):**

- The module has a real deletion rule (in use, has children, protected status) → add `markAsDeleted()` that enforces it and throws `{ModuleName}CannotBeDeletedException`.
- Something else reacts to the deletion (cache, audit, cleanup job) → the Entity also carries a `{ModuleName}DeletedEvent`.
- Neither applies → skip this task and have the Service call the repository directly after the existence check. Do not invent a rule the business does not have.

The Entity is `readonly`. `markAsDeleted()` enforces the rule and returns a **new** instance. It never mutates `$this` and never dispatches the event itself. It must not import anything from a framework.

```php
/**
 * Checks the delete rules and returns the entity with its deleted event.
 *
 * @throws {ModuleName}CannotBeDeletedException
 */
public function markAsDeleted(): self
{
    // TODO: replace with the module's real rule, e.g.
    // if ($this->isInUse) { throw new {ModuleName}CannotBeDeletedException(...); }

    return new self(
        // pass every constructor property by name, same values as $this,
        domainEvents: [...$this->domainEvents, new {ModuleName}DeletedEvent(id: $this->id)],
    );
}
```

The event is a plain PHP class (no framework base class) carrying plain data only:

```php
readonly class {ModuleName}DeletedEvent
{
    public function __construct(public ?int $id) {}
}
```

---

## Task 5: Command Service Implementation

**File:** `{ModuleName}CommandService.php`

Implement the `delete{ModuleName}` method following the logic below. The method must:

1. **Fetch the entity first** — inside the transaction callback, call `{entity}Repository->findByIdForUpdate($id)` (locking read) to ensure it exists before attempting deletion (and to retrieve file paths if file cleanup is necessary).
2. **Handle the not-found case** — if the repository returns nothing, return `Result::failure()` with the error key `{MODULE}_NOT_FOUND`. This is a guard **before** any write, so returning `Result::failure()` from inside the closure is correct here.
3. **Ask the Entity** — call `markAsDeleted()` (when Task 4 applies). The Entity throws `{ModuleName}CannotBeDeletedException` inside the closure. Catch it in its **own** `catch` block and convert it to `Result::failure()` with `{MODULE}_CANNOT_BE_DELETED`.
4. **Execute deletion** — call `{entity}Repository->delete($id)`. The port takes the **id**, not the Entity: the use case has no need for the row's state, so hydrating an entity to satisfy the port is work nobody reads. The port returns `void` and throws on failure — there is no `false` return to check.
5. **File cleanup (if applicable)** — if the entity has files/images (e.g. thumbnails), delete them through `FileUploaderContract` **only after** the database deletion is confirmed (never before — if the deletion later failed, you would have removed a file the DB row still references).
6. **Cleanup and events must not undo the delete.** Once the row is gone the use case succeeded. File cleanup and event dispatch each run in their own `try`/`catch`; a failure there is **logged** and the method still returns success. Never let those failures reach the generic `catch` and report a failed delete for a row that no longer exists.
7. **Dispatch released Domain Events** (released from the Entity `markAsDeleted()` returned, before the repository call) through `EventDispatcherContract` **after** the delete is confirmed (after the transaction commits). Skip this step when the module has no events.
8. **Catch unexpected errors** — `executeInTransaction()` already owns the generic `\Throwable` handling: it logs, cleans up, and returns a generic failure. Do not add a second `try`/`catch` for it.
9. **Return `Result`** with `data: null` — delete has no payload, so there is no ResponseDTO to carry.

> **Note on transactions:** every delete MUST run inside the transaction boundary (AGENTS.md §10, MANDATORY). It loads the row and then deletes it, which is a read-then-write sequence, and a cascade adds more writes. This project does **not** write `TransactionManagerContract::run()` inline in each Service method: `run()` plus the shared catch blocks live in ONE private `executeInTransaction()` (AGENTS.md §10 DRY), and every write method calls it. Load with `findByIdForUpdate()` inside the closure, THROW domain exceptions so the transaction rolls back, and never swallow a Throwable inside the closure after a write (the transaction would commit partial work). File cleanup and events run after `executeInTransaction()` returns.

```php
/**
 * Deletes a {entity}.
 */
public function delete{ModuleName}(int $id): Result
{
    $events = [];
    $filePath = null;

    $result = $this->executeInTransaction(
        operation: function () use ($id, &$events, &$filePath): Result {
            ${entity}Entity = $this->{entity}Repository->findByIdForUpdate($id);

            if (${entity}Entity === null) {
                // A guard that runs before any write may return a failure from inside the closure.
                return Result::failure(
                    message: $this->translator->translate(key: '{module}.not_found'),
                    errorCode: '{MODULE}_NOT_FOUND',
                );
            }

            // Business rule lives in the Entity (it throws when deletion is not allowed). Omit if the module has no delete rule/event.
            $deleted{ModuleName}Entity = ${entity}Entity->markAsDeleted();
            $events = $deleted{ModuleName}Entity->releaseEvents(); // BEFORE the repository call; omit if no events
            $filePath = ${entity}Entity->thumbnail; // omit if no file/image field

            $this->{entity}Repository->delete($id); // throwing port: void, no boolean to check

            return Result::success(
                data: null,
                message: $this->translator->translate(key: '{module}.deleted'),
            );
        },
        logMessage: '{ModuleName} deletion failed.',
        fallbackMessageKey: '{module}.errors.delete_failed',
        fallbackErrorCode: self::ERROR_DELETE_FAILED,
        context: ['{entity}_id' => $id],
    );

    if ($result->isFailure()) {
        return $result;
    }

    // The transaction committed and the row is gone. Side effects below are logged on failure and never turn this into a failure.
    $this->removeFileQuietly($filePath); // omit if no file/image field
    $this->dispatchEventsQuietly($events); // omit if no events

    return $result;
}

private function removeFileQuietly(?string $path): void
{
    if ($path === null) {
        return;
    }

    try {
        $this->fileUploader->delete($path);
    } catch (\Throwable $exception) {
        $this->logger->warning("Could not remove file [{$path}] after {module} delete: " . $exception->getMessage());
    }
}

/** @param list<object> $events */
private function dispatchEventsQuietly(array $events): void
{
    try {
        foreach ($events as $event) {
            $this->eventDispatcher->dispatch($event);
        }
    } catch (\Throwable $exception) {
        $this->logger->error("Could not dispatch {module} delete events: " . $exception->getMessage());
    }
}
```

Two details the reference makes concrete, both required by AGENTS.md:

- **`executeInTransaction()` is the shared private helper** (`operation`, `logMessage`,
  `fallbackMessageKey`, `fallbackErrorCode`, `context`). It runs the closure inside
  `TransactionManagerContract::run()`, catches `{ModuleName}CannotBeDeletedException` and
  `\Throwable`, and returns the `Result`. Copy the real signature from
  `core/Company/Application/Services/CompanyCommandService.php` rather than inventing it.
- **User-facing text comes from `TranslatorContract`,** never a hardcoded English string in Core
  (AGENTS.md §D5, §11). Keys live in `lang/<locale>/<module>.php`.

The Command Service constructor must receive `TransactionManagerContract`, `TranslatorContract`,
`LoggerContract`, `FileUploaderContract` (when the module has a file/image column) and
`EventDispatcherContract` (when the module raises events), through constructor property
promotion. If an adapter for a newly injected port is missing from `core/Shared/Infrastructure/`,
create it.

---

## Task 6: Repository Layer Implementation

**Files:** `core/{ModuleName}/Domain/Contracts/{ModuleName}RepositoryContract.php` (write port)
and `core/{ModuleName}/Infrastructure/Repositories/Wp{ModuleName}WriteRepository.php` (adapter)

This project has **no Eloquent**. There is no `Model`, no `->save()`, no `->delete()` on a model,
no `$fillable`, and no `findRecord()`. Both base classes sit over `$wpdb`
(`02-framework-adapter-wordpress.txt` s7) and Fluent Database is **paused** (AGENTS.md §D6):

| Class | Layer | Role |
| --- | --- | --- |
| `AbstractWpRepository` | Shared, Infrastructure | connection base: `db()`, `tableName()`, `escapeIdentifier()`. No read/write method. |
| `BaseWriteRepository` | Shared, Infrastructure | extends the above: `insert()`, `write()`, `deleteRecord()`, `findRecordForUpdate()`, `rowExists()`, duplicate handling. |
| `BaseReadRepository` | Shared, Infrastructure | shared read helpers `findRecordById()` and `rowExists()`; carries no write method, so the read/write boundary holds by *inheritance*, not discipline. |

Declare on the contract — the port takes the **id** and returns `void`:

```php
/**
 * Deletes a {entity} row.
 *
 * @throws \RuntimeException When the row cannot be deleted.
 */
public function delete(int ${entity}Id): void;
```

Implement in the adapter by extending `BaseWriteRepository` and delegating to the inherited
`deleteRecord()` helper. The adapter holds **no business logic**: it moves rows, nothing more.

```php
/**
 * @implements {ModuleName}RepositoryContract
 */
final class Wp{ModuleName}WriteRepository extends BaseWriteRepository implements {ModuleName}RepositoryContract
{
    public function delete(int ${entity}Id): void
    {
        $this->deleteRecord(${entity}Id, $this->tableName());
    }

    protected function tableName(): string
    {
        return self::TABLE;
    }

    private const TABLE = '...'; // private constant at the top of the class
}
```

Rules the generated adapter must satisfy:

- **Extends exactly one base.** A write adapter extends `BaseWriteRepository`; a read adapter
  extends `BaseReadRepository`. Never both ports on one class, and never a write method on a read
  adapter.
- **The `Wp` prefix** is mandatory (`Wp{ModuleName}WriteRepository`), per §D4.
- **`deleteRecord()` returns `bool`, the port returns `void`** — let it throw. If the helper
  returns `false`, throw a `\RuntimeException`; never swallow it silently (§11). The Service
  never sees a `$wpdb`/vendor exception: Infrastructure translates storage failures, and a
  duplicate-key failure becomes a Shared Domain exception (§11, NO VENDOR EXCEPTIONS IN CORE).
- **Every value goes through a prepared statement.** No string interpolation into SQL; the base
  class's `placeholderFor()`/`formatsFor()` exist for this. A raw interpolated identifier or
  value is a security defect, not a style issue.
- **`declare(strict_types=1);`** and `final` on the adapter class.

---

## Task 7: Unit Tests (moved to rule 07 - paused)

Testing is paused (`AGENTS.md` - UNIT TESTING POLICY). Do not write or claim tests. Test requirements live in `.claude/rules/07-testing.txt`, which is loaded only when the developer asks for tests.

---

## Common Pitfalls Checklist (Anti-Hallucination / No-Shortcut Rules)

The agent must self-verify against every item below **by checking the actual generated code**, not from memory. Claim only what the code really contains. A false claim is a defect.

- [ ] Code snippets in this file are reference implementations, not literal code. Verify every signature and convention against `AGENTS.md` and the existing codebase before writing.
- [ ] The route is a hand-written `Route::post('{module}/delete', ...)` in the existing `routes/web.php` admin group, with `->name()`, `->withOptions(['action' => ...])` and `->middleware(['nonce:' . {ModuleName}Controller::DESTROY_ACTION])`. No `Route::resource()`, and no direct `register_rest_route()`/`add_menu_page()` call.
- [ ] Deletion is a `POST`, not a `GET` or a bare `<a href>`. A state-changing `GET` is a vulnerability.
- [ ] No `<a>` tags for deletion. The delete action in the frontend is a `<form method="post">` carrying the nonce field and the row id.
- [ ] The view is `views/{module}/index.php`, plain PHP — **no** `.blade.php`, no `@csrf`, no `@method`, no `resources/views/`.
- [ ] The per-row delete form is rendered **outside** the bulk-actions form (nested forms are illegal HTML) and referenced with the button's `form` attribute; one form per row.
- [ ] Controller reads the id from the POST body via its `submittedId()` guard (no `{id}` route parameter), guards `null` before calling the Service, and uses a guard clause on `$result->isFailure()`, not a ternary.
- [ ] Controller flashes `notice` on success (not `success`) and calls `$result->message()` as a method.
- [ ] Delete logic is in the Command Service, not a Query Service.
- [ ] Business rules live in the Entity. The Service never reads entity fields to decide whether deletion is allowed.
- [ ] Entity stays `readonly`; `markAsDeleted()` returns a new instance and never dispatches the event.
- [ ] No bypassed validation in Service. The Service checks that the entity exists before issuing a delete command to the repository.
- [ ] `errorCode` values are domain keys (`{MODULE}_...`), never raw HTTP statuses like `'404'`.
- [ ] No layer leakage. Controller does not touch the Repository; Repository returns only domain types or `void`/booleans, never `Result` objects.
- [ ] The write port's `delete()` takes the **id** (`int`), not an Entity or a loose array.
- [ ] The adapter extends `BaseWriteRepository` (never `BaseReadRepository`, never both) and is named `Wp{ModuleName}WriteRepository`.
- [ ] No `Model`, `$fillable`, `->save()`, `->delete()` on a model, or `DB::` facade anywhere. No `Framework\Database\*` import while Fluent is paused.
- [ ] Every SQL value/identifier is prepared or escaped (`02-framework-adapter-wordpress.txt` s7). No interpolation into SQL.
- [ ] No framework import or facade in Core Domain/Application. Ports use the `...Contract` suffix.
- [ ] No skipped file cleanup. If the entity has an associated image/file, it is actually removed from storage after a successful database deletion — not left as a `TODO` comment.
- [ ] File cleanup and event dispatch happen strictly _after_ the transaction commits, never before.
- [ ] A failing file cleanup or event dispatch is logged and does not turn a completed delete into a failure.
- [ ] No raw exception exposure. The generic `\Throwable` handling lives in the shared `executeInTransaction()`; the Service does not add a second one.
- [ ] The whole delete (lookup with `findByIdForUpdate()`, Entity rule, repository delete, cascade) runs inside the transaction boundary via `executeInTransaction()`, with no manual `beginTransaction()`/`commit()`/`rollback()` and no `DB::` facade; failures after a write are thrown and converted OUTSIDE the transaction.
- [ ] Core contains no hardcoded user-facing string: messages come from `TranslatorContract` with keys defined in `lang/<locale>/<module>.php`.
- Unit tests are paused (`AGENTS.md` - UNIT TESTING POLICY). Do not write or claim them.
- [ ] After adding files under a new namespace path, run `composer dump-autoload`.

---

## Deliverable

Output all updated and newly created files. End with the verification checklist above, filled in only from what the generated code actually contains.
