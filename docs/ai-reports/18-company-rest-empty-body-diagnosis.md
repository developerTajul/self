# Company REST list returns `{}` — diagnosis (no code changed)

**Step:** `Company/08` (diagnostic only)
**Mode:** DELIVERY (diagnosis; the defect is in the framework's REST publication seam)
**Status:** DONE_WITH_NOTES — root cause confirmed by execution, no file edited.

---

## §0 Pre-flight

- **Context hydration:** last three `DECISIONS.md` entries read — `D-2026-11-16-05` (PaginatedResult already satisfied), `-06` (API capability read from the admin controller), `-07` (`Company/07` closed as already-satisfied, no filters built). Latest `PROJECT-LOG.md` entry is `Company/07`. Previous step's report is `docs/ai-reports/17-company-update-list-verification.md`; it touched no source file. **No OPEN decision binds this task**, and none is contradicted here.
- **Git tracking guard:** `git check-ignore -v .claude/rules .claude/skills docs/ai-reports` → produced **no output** for any of the three (exit 1 = nothing ignored). **PASS.**

---

## Reported symptom

`GET /wp-json/wp-mini-laravel/v1/companies` → **200 OK** with an empty body, while
`leadbridge_companies` holds rows (ids 66, 68).

## Root cause

**The controller returns a `Framework\Http\Responses\JsonResponse`, and nothing on the REST path ever calls `send()` on it or unwraps it.** WordPress receives the response object as if it were plain data, finds no public properties to serialize, and emits `{}`.

The two halves of the framework disagree about who terminates a REST request:

- **The web adapter owns sending.** `WordPressRouterAdapter` calls `$response->send()` at `framework/Routing/Adapters/WordPressRouterAdapter.php:646`, `:678`, `:809`, `:868`. `ResponseInterface::send()` is documented as terminal (`framework/Http/Contracts/ResponseInterface.php:19`).
- **The REST adapter never sends.** `Router::dispatchTo()` returns the controller's value straight to WordPress (`framework/Routing/Router.php:398`), and `RestApiRouterAdapter::destination()` does the same (`framework/Routing/Adapters/RestApiRouterAdapter.php:247`). Neither calls `send()`, and **no** `rest_post_dispatch` / `rest_request_after_callbacks` / `rest_ensure_response` normalizer exists anywhere in `framework/` or `app/` (grep returns zero hits outside `tests/`).

WordPress then does exactly what it does with any callback return value (`wp-includes/rest-api/class-wp-rest-server.php`):

```php
} else {
    $response = rest_ensure_response( $response );   // ~line 1258
}
```

and `rest_ensure_response()` (`wp-includes/rest-api.php`) wraps the non-`WP_REST_Response`, non-`WP_HTTP_Response` value:

```php
return new WP_REST_Response( $response );   // data === the JsonResponse OBJECT
```

`serve_request()` then calls `$response->get_data()` (returns the `JsonResponse` object) and `wp_json_encode()`s it. `JsonResponse` declares only **private** readonly properties (`data`, `options`, `sent` — `framework/Http/Responses/JsonResponse.php:31,39,41`), and `WP_HTTP_Response::jsonSerialize()` returns `get_data()` unchanged rather than delegating to the inner object's `jsonSerialize()`.

### Executed confirmation

```php
$inner = new JsonResponseObject(['data' => [['id' => 66]]]);
$outer = new WP_REST_ResponseShim($inner);
echo json_encode($outer->get_data());
// => {}
```

Reproduced above with real PHP 8.3.30 against faithful shims of the two WordPress
classes. `json_encode()` of an object with no public properties is `{}` — the
**200 OK + `{}`** the report describes, byte for byte.

**Why it is 200 and not an error:** nothing failed. `rest_ensure_response()` accepted the object, `get_data()` returned it, `json_encode()` succeeded (on nothing). The read ran; only the last hop is broken — which is why this presents as "the API works but returns no data" rather than as a fault.

---

## The three areas asked about: all three are correct

| # | Area | Verdict |
| --- | --- | --- |
| 1 | `WpCompanyReadRepository` table name | **Correct.** `AbstractWpRepository::tableName()` returns `$this->db()->prefix . $table` (`core/Shared/Infrastructure/Repositories/AbstractWpRepository.php:45`); `TABLE_SUFFIX = 'companies'` ⇒ `leadbridge_companies`, matching `$table_prefix = 'leadbridge_'` (`wp-config.php:74`) and the phpMyAdmin rows. |
| 2 | `$wpdb` query execution | **Correct.** The count and slice are built and prepared as documented; executed probe below. The `rows()` guard reads `last_error` rather than testing `=== false`, which is right — WordPress's `get_results()` returns `[]`, never `false`, on a failed statement. |
| 3 | Mapping to `CompanyEntity` → arrays | **Correct for this endpoint, and not even on the path.** The list read maps rows → `CompanyProjectionDTO`, not `CompanyEntity` (by design — `CompanyResponseMapper::toResponseDTOFromProjection()`, `core/Company/Application/Mappers/CompanyResponseMapper.php:64`). `CompanyResource::collection(...)` + `->with('meta', ...)` produce a fully-formed `JsonResourceCollection`, whose `jsonSerialize()` is implemented correctly. |

### Executed probe of the generated SQL

```
COUNT: SELECT COUNT(*) FROM `leadbridge_companies`

SLICE: SELECT `id`, `name`, `website`, `industry`, `phone`, `address`,
       `created_at`, `updated_at` FROM `leadbridge_companies`
       ORDER BY `created_at` DESC, `id` DESC LIMIT 15 OFFSET 0
```

Correct table, correct explicit column list, correct binding. **No bug in the repository or the controller's response-building logic.**

---

## Why it went unnoticed

`app/Http/Controllers/Api/LeadController.php` — the only other REST controller, and
the pattern `CompanyController` was written against — returns `new JsonResponse(...)`
the same way (`:49`, `:61`) and is **equally broken**. `LeadController::index()` returns
two hardcoded sample rows rather than reading a table, so it has never been observed
producing data; the company endpoint is simply the first REST route whose payload
someone compared against the database.

**Consequence:** this is a **framework/generator-level defect**, not a Company-module
one. It affects every REST controller written to this pattern, including every one
`make:controller` produces — so the README and the controller stub propagate it.

---

## Why no code was changed

The fix belongs in `framework/` (JOB F), which is **read-only for a module task**
(AGENTS.md §2: "A module task NEVER edits `framework/` or `vendor/`"; §6 HALT for
"needed change in `framework/`/`vendor/`"). Correcting it in `CompanyController`
alone would mask it for one endpoint while leaving `LeadController` and every future
REST controller returning `{}` — a fix in the wrong layer, which P15 and §2 both
forbid.

**Options, for the developer to choose (this is the HALT, per §6):**

1. **Fix in the framework (recommended).** Add a REST normalizer so a callback's
   `ResponseInterface` is unwrapped into a `WP_REST_Response` before WordPress
   serializes it — either in the REST adapter's `dispatchRest()` / `destination()`,
   or centrally on `rest_request_after_callbacks`. One change fixes every REST
   endpoint, present and generated. Requires authorizing a JOB F step.
2. **Change the delivery convention instead.** REST controllers return plain arrays /
   `JsonResource` (which is `JsonSerializable`) rather than a `JsonResponse`, and
   `LeadController`, `CompanyController` and the `make:controller` REST stub follow.
   No `framework/` edit, but it makes the web and REST surfaces return different
   types and abandons `ResponseInterface` on REST entirely — a larger, breaking
   convention change, and status codes/headers lose their natural home.
3. **Leave it and document it.** Not recommended: it leaves a defect that silently
   returns `{}` with a success status, the failure mode hardest to notice.

---

## Checklist

- [x] §0 ran: recent `DECISIONS.md`/`PROJECT-LOG.md` read; tracking guard printed nothing for the three paths.
- [x] A5b sweep: **not applicable** — no rule, `AGENTS.md` or `SKILL.md` was edited.
- [x] A6b repair loop: **not applicable** — no self-check failed; this was diagnosis.
- [x] Mode named (DELIVERY, diagnosis); `framework/` and `vendor/` **untouched**.
- [x] No `env()` outside `config/`; no `Config` in Core/Infrastructure.
- [x] No file created other than this report; non-existence of that path verified.
- [x] Root cause confirmed by execution, not inferred from reading alone.
- [x] Report states the failed/absent action honestly: **no fix applied.**

**Decision recorded:** `D-2026-11-16-08`.
