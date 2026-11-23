# REST `{}` under 200 — the unwrap was on a dead path (fix)

**Step:** `Framework/02` (authorized JOB F, developer chose option 1).
**Mode:** FRAMEWORK.
**Status:** DONE_WITH_NOTES — fix applied to `framework/Routing/Router.php`, proven by execution.

---

## §0 Pre-flight

- **Context hydration:** last three `DECISIONS.md` entries read — `D-2026-11-16-07` (Company/07 closed as already-satisfied), `-08` (the `{}` defect raised as a Structured HALT), `-09` (`Framework/01` unwrapped a `ResponseInterface` in the REST adapter and added `data()` to the contract). Latest `PROJECT-LOG.md` entry is `Framework/01`. **`D-2026-11-16-09` is OPEN and binds this task** — it recorded the fix as done and proven; this step records that it was applied to an object the live path never calls, and completes it. No other OPEN decision touches this task.
- **Git tracking guard:** `git check-ignore -v .claude/rules .claude/skills docs/ai-reports` → **no output** (exit 1 = nothing ignored). **PASS.**

---

## Symptom after `Framework/01`

`GET /wp-json/wp-mini-laravel/v1/companies` still answered **200** with body `{}`,
while `leadbridge_companies` held rows — unchanged from `Company/08`.

## Root cause: the fix landed on a router that publishes nothing

`Framework/01` added `toRestResponse()` to `RestApiRouterAdapter`. That adapter is
bound (`app/Providers/RouteServiceProvider.php:55`) but **never fed the routes and
never asked to publish them**:

- The live REST path is `framework/Routing/RouteServiceProvider.php:107-124`, which
  resolves **`Router`** — not the adapter — loads `routes/api.php` into it, and calls
  `$router->registerRoutes($namespace)`.
- `Router::registerRoutes()` (`framework/Routing/Router.php:236`) builds the WordPress
  callback as `fn (WP_REST_Request $r) => $this->dispatch($route, $r)`.
- `dispatch()` → `dispatchTo()` → `return $this->resolver->call(...)` — **the raw
  controller return value, unwrapped**. The adapter's new code was never on this path.
- `RestApiRouterAdapter::flush()` has **zero callers** in the repository; the only
  consumer of `'router.api'` is `route:list`. `RouteLoader` aims the `Route` facade at
  whatever router is loading the file — here, the `Router`.

So `D-2026-11-16-09`'s "proven by execution" was true of the **adapter in isolation**:
correct code, on a path no request travels. The same blind spot that let
`LeadController` ship broken.

### Executed confirmation (before the fix)

Real `JsonResponse` class plus faithful shims of `WP_REST_Response` /
`rest_ensure_response`, reusing the framework's own `toRestResponse()` verbatim:

| Path | Served body | Status |
| --- | --- | --- |
| **A — live `Router::dispatchTo()`** | `{}` | 200 |
| **B — `RestApiRouterAdapter::toRestResponse()`** | `{"data":[{"id":66,"name":"Acme"}],"meta":{"total":2}}` | 200 |

Path A reproduces the reported symptom byte for byte; Path B proves the earlier fix
was correct but unreachable from this project's routes.

---

## The three areas the brief asked about: all three were already correct

| # | Area | Verdict |
| --- | --- | --- |
| 1 | `CompanyController::index()` | **Correct.** Fetches `PaginatedResult` from the Query Service via the Request Mapper, maps a `InvalidArgumentException` from the mapper to a 422, and builds `CompanyResource::collection($page->items())->with('meta', $page->meta())`. |
| 2 | `CompanyResource` / `CompanyResponseMapper` | **Correct.** `CompanyResource::toArray()` names each field; `whenPropertyExists()` builds the relation value inside the callback so a list item does not read a missing property. The list read maps rows → `CompanyProjectionDTO` → `toResponseDTOFromProjection()`, never hydrating an entity. `JsonResource::jsonSerialize()` is implemented. |
| 3 | Step-by-step trace | **The payload is fully formed at the controller's `return`.** It is lost at the publication hop, not inside the module. |

No defect existed in the Company module or the mapper chain, and none was invented to
match the brief's hypothesis.

---

## Fix applied

`framework/Routing/Router.php` — the legacy `Router`, which is the object actually
publishing this project's REST routes:

1. `dispatch()` now wraps its result: `return $this->toRestResponse($this->withHttpRequest(...))`.
   The unwrap is applied **outside** the `withHttpRequest()` closure so the
   request-scoped binding behaves exactly as before — the closure's contents are
   otherwise untouched.
2. New private `toRestResponse(mixed): mixed` — a `ResponseInterface` becomes
   `new WP_REST_Response($result->data(), $result->status(), $this->responseHeaders($result))`.
   Anything else is returned untouched, so `ping`'s array, this class's own
   `WP_Error` failure paths and any already-wrapped `WP_REST_Response` are unaffected.
3. New private `responseHeaders(ResponseInterface): array` — reads the `headers`
   option through `method_exists`, mirroring the adapter so a 201's `Location` and a
   429's `Retry-After` survive.
4. Imports `Framework\Http\Contracts\ResponseInterface` and `WP_REST_Response`.
5. Class docblock corrected: it claimed `RestApiRouterAdapter` supersedes this class
   for new code without saying the two are alternatives. It now states a project
   publishes through one of them, that `RouteServiceProvider` publishes through this
   class, and that both carry the same translation.

`dispatch()` has exactly one caller (the REST callback), so the restructure cannot
reach web routing, which owns sending through `WordPressRouterAdapter`.

## Verification — the real edited method, not a copy

`toRestResponse()` was invoked on the **actual class file** via reflection, with
WordPress shims for `WP_REST_Response` / `rest_ensure_response`:

| Case | Result |
| --- | --- |
| List payload | `{"data":[{"id":66,"name":"Acme"}],"meta":{"total":2}}`, status 200 |
| 201 + `Location` | status `201`, headers `{"Location":"/x/7"}` |
| 204, null body | body `null`, status `204` |
| `array` / `WP_REST_Response` / `null` / `int` / `string` | passed through untouched, same type out |

`php -l` clean on `Router.php` and `RestApiRouterAdapter.php`.

**Not verified in a live WordPress request.** No fix was applied to the generator or
to `LeadController`; see the notes below.

---

## Deliberately left alone

- **`RestApiRouterAdapter::toRestResponse()`** — kept. Both routers can be the object
  publishing a project's REST routes and a project uses one of them; removing it would
  re-break any project wired through `'router.api'`.
- **The duplicate implementation** (two routers, one behaviour) is the deeper smell.
  Collapsing it is option 2 from the `Company/08` halt — a migration touching
  `route:list`, `RouteLoader` and `NamedRoutes`, not a bug fix. Recommended as its own
  step.
- **`LeadController`** — no longer broken by this defect (it returns `JsonResponse`, now
  correctly unwrapped), but its `index()` still returns hardcoded sample rows rather
  than reading a table. Out of scope here; worth its own step.

---

## Checklist

- [x] §0 ran: recent `DECISIONS.md`/`PROJECT-LOG.md` read; tracking guard printed nothing for the three paths.
- [x] A5b sweep: **none** — swept `.claude/skills/**/SKILL.md` for `WP_REST_Response` and `JsonResponse` (the terms this edit makes newly load-bearing); zero hits. No skill instructs the old shape. No rule, `AGENTS.md` or `SKILL.md` was edited.
- [x] A6b repair loop: **not applicable** — no self-check failed.
- [x] Mode named (FRAMEWORK, developer-authorized); `vendor/` untouched; `core/` and `app/` untouched by this step.
- [x] Non-existence of the created report path verified before writing.
- [x] Verification audits the real file; the fix is proven by execution, not by reading.
- [x] Report states honestly what was and was not verified: **no live WordPress request was run.**

**Decision recorded:** `D-2026-11-16-10`.
