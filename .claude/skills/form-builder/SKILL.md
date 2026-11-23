---
name: form-builder
description: Scaffolds the delivery layer for a module's create form (route, thin controller create action, PHP form view) strictly from a provided database schema. Use when asked to build or scaffold a create/add form for a module. Does not implement store or persistence logic.
---

# Form Builder Skill (Delivery Layer Scaffolding)

## Description

This skill generates the WordPress delivery layer needed to render a create form, based STRICTLY on a provided database schema. It follows the request lifecycle (Route -> Controller -> View) and prepares the user interface gateway without implementing any data-persistence logic.

> **This work follows the Rich Domain Model.** The form scaffold is delivery code only: it holds no business rules and touches no Entity, Service, or Repository. Business rules (validation of state, derived values) belong to the Entity and arrive later with the store use case. Read `AGENTS.md` and `.claude/rules/02-framework-adapter-wordpress.txt` (routing, views, nonce) before writing code.

> **Autonomous runs:** when this skill is executed as a plan step (Autonomous Execution Mode), also follow `.claude/rules/09-autonomous-execution.txt`: put the verification checklist into the step report's Self-check table with evidence, write the step report, and record decisions in `docs/ai-reports/DECISIONS.md`. Where this skill says to stop and ask or tell the developer, apply 09 instead: additive Shared additions and the wiring files are in scope (A4) and are done and recorded, and only the hard stops in A10 halt the run.

**Placeholders used in this skill:**

| Placeholder    | Meaning                               | Example                        |
| -------------- | ------------------------------------- | ------------------------------ |
| `{ModuleName}` | PascalCase module name                | `Category`, `Product`, `Brand` |
| `{module}`     | lowercase/plural route & path segment | `categories`, `products`       |

## Context & Rules

- **Source of Truth:** You MUST analyze the provided SQL database schema or field list exactly. Explicitly skip primary keys (e.g., `id`), auto-managed timestamps (e.g., `created_at`, `updated_at`), soft deletes (e.g., `deleted_at`), and domain-generated fields (e.g., `slug`, `uuid`, `token`). Do not invent or assume extra fields outside of this context. If a column type is not covered by the mapping table below, do not guess: render a plain text input and flag it in a comment for the developer.
- **Empty-Skeleton Guard:** The controller acts only as a view renderer at this stage. Do NOT write `store()`, a FormRequest, a Service call, a mapper call, or any data-processing logic yet.
- **Delivery layer only:** Everything generated lives under `app/` and `views/`. Do not create or edit anything under `core/` (no Entity, Service, Repository, or DTO).
- **No business logic in the view or controller:** no rules, no calculations, no Entity or Repository access.
- **Form Method Policy:** Forms must strictly use the POST method, preparing them for future data submission. Do not add a method-override field; that is only for PUT/PATCH/DELETE forms, which WordPress's admin form path does not support.
- **Scope:** Create or edit only the files listed in the Execution Steps. If something outside that list seems necessary, stop and ask first (AGENTS.md - STRICT SCOPE ADHERENCE).
- **Verify before writing:** Check the existing views directory and layout, controller namespace, and route file for the project's real conventions. Follow what exists; never guess a namespace or path.

## Execution Steps

1. **Route Architecture Wiring (`routes/web.php`):**
    - This is a **create screen**, so it is a hidden admin screen reached from the index, declared with the `Route::` facade (`Framework\Support\Facades\Route`) inside the existing admin group. Never call `register_rest_route()`/`add_menu_page()` directly.
    - The path comes from a class constant (`CREATE_SLUG`) so it cannot drift from the controller's own links.
    - Set `hidden => true` unless the screen has its own menu entry: a create screen without a `parent_slug` registers as a top-level menu pointing at a form no menu should own.

```php
Route::get({ModuleName}Controller::CREATE_SLUG, [{ModuleName}Controller::class, 'create'])
    ->name('{module}.create')
    ->withOptions([
        'page_title'  => 'Add a {module}',
        'menu_title'  => 'Add a {module}',
        'capability'  => {ModuleName}Controller::CAPABILITY,
        'parent_slug' => {ModuleName}Controller::SCREEN_SLUG,
    ]);
```

    This yields the create screen at the controller's own slug, named `{module}.create`, mapped to the Controller's `create` method. A `GET` screen carries **no nonce** — it changes nothing; only `POST` write routes get `->middleware(['nonce:' . ...])`.

2. **Controller Generation (`App\Http\Controllers\Admin\`):**
    - Generate a thin HTTP Controller named `{ModuleName}Controller.php` (verify the namespace against the existing controllers).
    - Declare `declare(strict_types=1);` and make the class `final`, extending the project's base `Controller`.
    - Include an empty constructor ready for future dependency injection. Leave a TODO naming what will be injected later: `{ModuleName}CommandServiceContract` and `{ModuleName}MapperContract` for the store use case, injected through the constructor with property promotion (inject the CONTRACT, never the concrete Service class).
    - Create a single `create()` method that strictly returns the view (e.g. `view('{module}.create')`).
    - Write DocBlocks in the project's plain style: short, human, 1-2 lines (e.g. "Shows the create form.").

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

final class {ModuleName}Controller extends Controller
{
    public function __construct()
    {
        // TODO: inject {ModuleName}CommandServiceContract and {ModuleName}MapperContract
        //       (constructor property promotion) when the store use case is built.
    }

    /**
     * Shows the create form.
     */
    public function create(): View
    {
        return view('backend.pages.{module}.create');
    }
}
```

3. **Form View Generation (`views/{module}/create.php`):**
    - The path and view name above follow the project's existing views (the same directory as `index.php` and `edit.php`). Verify against the real views directory and use it. Do not create a parallel `admin/` tree, and do not use Blade — this project has no template engine.
    - Create a semantic, accessible HTML form structure: every input has a `<label for>` that matches its `id`, and required columns (NOT NULL without a default) get the `required` attribute.
    - STRICTLY set `enctype="multipart/form-data"` ONLY if the schema includes file/image columns (e.g., thumbnail, image, logo).
    - Include the **nonce field** the controller passes in (`$nonceField`), not a `@csrf` directive: a WordPress admin form carries a nonce, and the field markup comes from `wp_nonce_field()` via the controller.
    - Map columns 1:1 to appropriate HTML5 input types:

        | Column type                 | Control                                            |
        | --------------------------- | -------------------------------------------------- |
        | VARCHAR                     | `<input type="text">`                              |
        | TEXT                        | `<textarea>`                                       |
        | INT / DECIMAL               | `<input type="number">` (with `step` for decimals) |
        | TINYINT(1) / ENUM           | `<select>` with the schema's values                |
        | file/image column           | `<input type="file">` (with `enctype` above)       |

    - Read submitted values back through the project's `old('field', default)` helper in the `value` attributes (and `selected` / textarea content) to preserve state upon a rejected submission. This is the framework's own helper, not Laravel's `old()`.
    - Escape every interpolated output with `e()`.
    - Select options come from the schema's values as written. Do not call Domain enums, Services, or models from the view. Wiring options from the Domain enum comes later through a Query Service and ResponseDTO.

## Testing & Verification

- **Temporary Action:** For initial scaffolding, set the form `action` attribute to `#` with `method="POST"`. This lets the form render without a `RouteNotFoundException` before the store pipeline exists.
- **Visual Feedback:** Include a small visible alert or comment block inside the view reminding that the store route is not yet defined (e.g. `admin.{module}.store`), so it is easy to spot during early UI testing. Do not leave `#` without this reminder.
- **Tests:** Unit tests are paused (`AGENTS.md` - UNIT TESTING POLICY). Do not generate any.

## Verification Checklist (fill in only from what the generated code actually contains)

Before reporting done, audit the generated files. Claim only what you can point to in the code. A false claim is a defect.

- [ ] Only schema columns appear in the form; `id`, timestamps, `deleted_at`, and domain-generated fields (`slug`, `uuid`, `token`) are absent.
- [ ] Route is a hidden/child admin screen declared with `Route::get(...CREATE_SLUG...)` inside the existing group (`hidden` or `parent_slug` set), with no `Route::resource()` and no direct `register_rest_route()` call.
- [ ] Controller is `final`, has `declare(strict_types=1);`, an empty constructor with a TODO, and only a `create()` method that returns the view.
- [ ] No `store()`, FormRequest, Service, mapper, or Repository usage was added.
- [ ] Nothing under `core/` was created or changed.
- [ ] Namespace, view path, and view name match the existing codebase (verified, not guessed).
- [ ] Form uses `method="post"` with the nonce field the controller passes in, and the real form action — or `action="#"` with the visible "store route not defined yet" reminder if the store route genuinely does not exist yet.
- [ ] `enctype="multipart/form-data"` is present if and only if the schema has file/image columns.
- [ ] Every input has a matching `<label for>` / `id`, `old()` is used for every field, `required` follows the schema, and every output value is escaped with `e()`.
- [ ] The view is plain PHP in `views/{module}/create.php` — no Blade, no `@csrf`, no `@method`, no `resources/views/`.

## Output

Deliver the generated code components cleanly. You MUST wrap each code component inside standard markdown code blocks (using backticks with the language, such as `php` and `html`) to strictly preserve line breaks, indentation, and formatting. Keep conversational text to an absolute minimum, and end with the filled-in verification checklist.
