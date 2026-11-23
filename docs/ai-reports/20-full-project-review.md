# Full Project Review (Review/01)

**Status:** DONE_WITH_NOTES
**Date:** 2026-11-16
**Branch:** `wpdb`
**Scope:** read-only. Every file under `core/`, `app/`, `routes/`, `config/`, `lang/`, `views/`, `database/`; `framework/` read for boundary checks only (JOB F untouched).
**Previous step:** `Framework/02` (`docs/ai-reports/19-rest-response-unwrap-live-path.md`)

## 0 Pre-flight

- **Context hydration:** `DECISIONS.md` tail read — D-2026-11-16-08 (Structured HALT, later resolved by 09/10), D-2026-11-16-09 (`data()` added to `ResponseInterface`), D-2026-11-16-10 (`toRestResponse()` ported into `Router`; collapsing the duplicate router recommended as its own step). Latest `PROJECT-LOG.md` row: `Framework/02` DONE_WITH_NOTES. Previous report 19 read.
- **Git tracking guard:** `git check-ignore -v .claude/rules .claude/skills docs/ai-reports` printed nothing for any of the three; exit 1. PASS.
- **Working tree:** 24 tracked files modified (`git diff --stat`: 883 insertions, 112 deletions) plus untracked files from `Company/06`–`Framework/02`, all uncommitted since `c9b43d3`. No file was changed by this review.
- **Modes examined:** DELIVERY (`app/`, `routes/`, `views/`), ADAPTER (`core/*/Infrastructure`, `app/Database`), CORE-PURE (`core/*/Domain`, `core/*/Application`), FRAMEWORK (boundary read only).

**Applicable rule sections:** AGENTS.md §§1–3, 5, 7–11, 14; 01 P1–P6; 02 §2/§2.3/§5/§6; 03 §BASE REPOSITORIES/7.1; 05 R1–R12; 06 T1–T10; 09 A9/A10; 10 F1/F4.

## 1 Method

Static audit only; no source edited, no test run (07 paused), no migration executed.

| Check | Command shape |
| --- | --- |
| Namespace/import resolvability | autoload every `use Core/App/Framework` target, `class_exists` |
| Class-load feasibility | `class_exists()` per suspect adapter in a fresh process |
| Syntax | `php -l` over all `core/ app/ routes/ config/ views/` files |
| Zone boundaries | grep for `App\Models`, `Illuminate\`, `use App\`, `use Framework\`, WP functions in Domain/Application |
| Wiring | compare contracts referenced in `app/` and declared in `core/` against bindings in `app/Providers` |
| Domain/transaction shape | grep for `run()`, `executeInTransaction`, `beginTransaction`, `releaseEvents`, `EventDispatcherContract` |
| Delivery security | nonce/capability middleware, `$wpdb->prepare`, `esc_*` in views, superglobals |

## 2 Verdict

The **Company module is the only feature that is ported, wired and rule-compliant end to end**. Everything else is at an earlier porting stage and must not be treated as working code: seven modules still carry Laravel/Eloquent infrastructure inside `core/`, most of their contracts are unbound, and two adapter classes fail at class-declaration time. The framework, the shared repository bases, the Company module and the delivery security posture are in good shape.

| Severity | Count | Meaning |
| --- | --- | --- |
| **P0 blocker** | 4 | code cannot load or cannot run under WordPress |
| **P1 high** | 6 | rule violations in shipped/paths that will run, or required infrastructure absent |
| **P2 medium** | 8 | portability/naming/consistency defects |
| **P3 low** | 5 | hygiene, documentation drift |

## 3 Module status matrix

| Module | Domain/App WP-free | Infrastructure | Bound in a provider | Reachable from delivery | Verdict |
| --- | --- | --- | --- | --- | --- |
| `Company` | yes | `$wpdb` (`Wp*`, extends bases) | yes (`CompanyServiceProvider`) | web + REST | **production-ready** |
| `Lead` | yes | `$wpdb`, but **not** on the shared bases; read side on the paused fluent builder | write/read ports only | REST shows hardcoded sample rows | partially ported, unwired |
| `Activity` | yes | Eloquent; write repo **fatal** | no | no | unported |
| `Auth` | yes | Eloquent + Sanctum token + Laravel hasher | no | no | unported |
| `Contact` | yes | Eloquent | no | no | unported |
| `Dashboard` | yes | Eloquent (3 repos) | no | admin screen renders static view only | unported |
| `Deal` | yes | Eloquent; write repo **fatal** | no | no | unported |
| `Pipeline` | yes | Eloquent | no | no | unported |
| `Shared` | yes | 8 `Laravel*` adapters + missing WP counterparts | 4 of 10 ports | — | mixed |

The Domain/Application layers are clean everywhere: no WordPress call, no `Framework\`, no `App\` import was found in `core/*/Domain`, `core/*/Application`, `core/Shared/{Domain,Application}`. The contamination is entirely in Infrastructure, which is the replaceable zone — but it has not been replaced yet.

## 4 Findings

### P0 — blockers

**P0-1 Seven modules still run on Laravel/Eloquent inside `core/`.**
20 files import `App\Models\*` and/or `Illuminate\*`:
`Activity/Infrastructure/*` (2), `Auth/Infrastructure/*` (2), `Contact/Infrastructure/*` (2), `Dashboard/Infrastructure/*` (3), `Deal/Infrastructure/*` (4), `Pipeline/Infrastructure/*` (2), and `Shared/Infrastructure/{Database,Queues,Events,Localization,Security,Storage,Repositories}/*` (5).
Rule: AGENTS.md §1/§2 (Infrastructure is replaced, never imports `App\`), 02 §2.3.
Impact: `App\Models` does not exist in this plugin, so these classes cannot function; the modules are dead weight that still autoload and still advertise contracts.

**P0-2 16 referenced types do not exist.**
Autoload scan of every `use` target produced:
`App\Models\{Activity,Company,Contact,Deal,DealStage,Lead,Pipeline,User}`, `App\Jobs\{ProcessInboundLead,UpdateStatusJob}`, `Core\User\Application\Contracts\{UserEntityQueryContract,UserQueryContract}`, `Core\User\Domain\{Entities\UserEntity,Exceptions\InvalidUserException,Exceptions\UserCannotAuthenticateException,ValueObjects\UserFilter}`.
The whole `Core\User` module is absent while five modules reference it. Rule: §11 (Core never depends on a non-existent type), 06 T1.

**P0-3 Two adapter classes are fatal at load time.**
`class_exists('Core\Activity\Infrastructure\Repositories\ActivityWriteRepository')` →
`Fatal error: Access level to ...ActivityWriteRepository::write() must be protected (as in class Core\Shared\Infrastructure\Repositories\BaseWriteRepository) or weaker ... ActivityWriteRepository.php on line 63`.
`Core\Deal\Infrastructure\Repositories\DealWriteRepository` → same at `DealWriteRepository.php:113`.
Cause: `core/01` added `protected function write(int, array, string, string)` to `BaseWriteRepository` (line 144) while these two unported repositories still declare `private function write(Entity, Model)`. `ActivityWriteRepository::__construct()` also passes an Eloquent model to `AbstractWpRepository::__construct(?wpdb)`. Rule: AGENTS.md §14 (completion checklist), 06 T8/T9 — the port audit was never run against the unported modules.

**P0-4 No WordPress adapters exist for six required shared ports; `ClockContract` does not exist at all.**

| Port | Contract | Its only implementation | WordPress adapter |
| --- | --- | --- | --- |
| `EventDispatcherContract` | present | `LaravelEventDispatcher` (`event()`) | **missing** (`WpEventDispatcher`) |
| `QueueManagerContract` | present | `LaravelQueueManager` (`dispatch()`, `App\Jobs`) | **missing** (`WpQueueManager`) |
| `FileUploaderContract` | present | `LaravelFileUploader` | **missing** (`WpFileUploader`) |
| `SlugCheckerContract` | present | `EloquentSlugChecker` | **missing** (`WpSlugChecker`) |
| `PasswordHasherContract` | present | `LaravelPasswordHasher` | **missing** (`WpPasswordHasher`) |
| `TokenGeneratorContract` | present | `SanctumTokenGenerator` (`App\Models\User`) | **missing** (`WpTokenGenerator`) |
| `ClockContract` | **missing** | — | — (`SystemClock` also missing) |

Rule: AGENTS.md §11 and 02 §2.3 table. Any module that raises events, pushes a job, or authenticates cannot be resolved — and `LeadConversionService`, `AuthCommandService`, `ActivityCommandService` all inject `EventDispatcherContract`.

### P1 — high

**P1-1 Shared ports are bound in a module-named provider, and `AppServiceProvider` is empty.**
`app/Providers/AppServiceProvider.php` is a 19-line stub. `TransactionManagerContract`, `RateLimiterContract`, `TranslatorContract`, `LoggerInterface` are all bound in `app/Providers/RepositoryServiceProvider.php:34–120`, whose name claims the lead module. Rule: P1 / 02 §5.2 ("shared ports bind once in `AppServiceProvider`"). Consequence: whichever provider is listed first owns the shared kernel by accident, not by design.

**P1-2 54 of 65 contracts have no binding.**
Only `Company*` (6), `LeadRepositoryContract`, `LeadQueryContract` and the four shared ports are bound. Nothing in `Lead` beyond its two repository ports is bound — `LeadCommandServiceContract`, `LeadQueryServiceContract`, all note/conversion/owner/timeline contracts — and no module provider exists for `Activity`, `Auth`, `Contact`, `Dashboard`, `Deal`, `Pipeline`. Rule: 02 §5.2/P1. Impact: the Lead module's own delivery cannot be wired without inventing it, and `php mini`/hooks resolving any of those services throw.

**P1-3 Adapters live in `app/`.** `app/Database/WpTransactionManager.php`, `app/Database/AtomicDatabaseRateLimiter.php`, `app/Cache/WpCacheStore.php` implement Core ports outside `core/Shared/Infrastructure/`. Two transaction adapters exist for one port (`app/Database/WpTransactionManager` and `core/Shared/Infrastructure/Database/LaravelTransactionManager`). Rule: AGENTS.md §2 ("Adapters never live in `app/`"), §10 ("one adapter"), 02 §2.3 names the canonical path.

**P1-4 `LeadReadRepository` imports the paused fluent builder.** `core/Lead/Infrastructure/Repositories/LeadReadRepository.php:12` `use Framework\Database\Query\QueryBuilderInterface;` (constructor `:57`). This is the only `Framework\` import in `core/`. Rule: D6 ("never import `Framework\Database\*`"), 06 T1. It also means the Lead read path does not use `BaseReadRepository`, so the shared read helpers are bypassed.

**P1-5 Lead repositories do not extend the shared bases.** `LeadWriteRepository` and `LeadReadRepository` declare `final class ... implements ...` with no base; every other `$wpdb` write repository extends `BaseWriteRepository`. Duplicate connection resolution, `last_error` handling and duplicate-key translation are re-implemented instead. Rule: D4/D6, 03 §BASE REPOSITORIES, AGENTS.md §9 table.

**P1-6 `ActivityCommandService` manages transactions by hand.** `beginTransaction()` at `:65,133,217`, `commit()` at `:87,166,239`, `rollback()` at `:89,96,140,168,178,223,241`. Rule: AGENTS.md §10 (mandatory `run()`/`executeInTransaction`), which every other command service follows (`LeadCommandService`, `CompanyCommandService`). This class also dispatches via `EventDispatcherContract` in a `try/catch` outside a transaction.

### P2 — medium

**P2-1 Read ports placed in Domain.** `core/Dashboard/Domain/Contracts/{DashboardBoard,DashboardFeed,DashboardMetrics}Contract.php`; the Dashboard module has no `<Module>QueryContract` in Application. Rule: §9 table, 02 §2. `Lead` and `Deal` follow the rule; `Dashboard` does not.
**P2-2 Command service named `ContactService`.** `core/Contact/Application/Services/ContactService.php:26`, contract is `ContactCommandServiceContract`. Rule: §8 (`<Module>CommandService`), 05.
**P2-3 Services depend on concrete response mappers.** `Lead`, `Contact`, `Deal`, `Pipeline`, `Dashboard`, `Activity`, `Auth` query/command services type-hint `*ResponseMapper` classes rather than `*MapperContract`; only `Company` injects `CompanyResponseMapperContract`. Rule: P1/§8 inbound contracts; a concrete dependency inside Application is not replaceable when the module is ported.
**P2-4 `Core\Shared` files outside the three zones.** `core/Shared/RateLimitResult.php` (`namespace Core\Shared`) and `core/Shared/Exceptions/DomainValidationException.php` (`Core\Shared\Exceptions`). Every other shared type lives under `Domain`, `Application` or `Infrastructure`. Rule: §1 zone table; a Shared root has no defined dependency direction.
**P2-5 Entities/DTOs not matching §5/§8 shape.** `ActivityEntity` is `final class` and mutable (`?:18`, `private array $recordedEvents`, private constructor + mutators) — rule requires `readonly class`, not final. `Auth` DTOs (`LoginRequestDTO`, `ProfileRequestDTO`, `RegisterRequestDTO`, `AuthResponseDTO`) and `Lead` DTOs (`LeadCreateDTO`, `UpdateLeadStatusQueueDTO`) are `readonly class` without `final`.
**P2-6 Duplicate REST unwrap.** `Router::toRestResponse()`/`responseHeaders()` and `RestApiRouterAdapter::toRestResponse()`/`responseHeaders()` are two copies of the same mapping; the adapter's `flush()` still has no callers. Known and deliberate (D-2026-11-16-10), still live debt: the next fix will land in one copy again.
**P2-7 `lang/` covers only the Company module.** `WpTranslator` returns the raw key when a file/key is missing, and Core asks for `leads.errors.*`, `deals.errors.*`, `contacts.errors.*`, `pipelines.errors.*`, `auth.errors.*`. Those keys resolve to themselves. Latent while those modules are unwired; blocking the moment one is wired.
**P2-8 Provider bootstrap swallows errors.** `app/Kernel.php` catches `\Throwable` around each provider registration and only `error_log()`s it, so a typo in `config/app.php` degrades one provider silently. Not a rule breach; a diagnosability defect (AGENTS.md §15 depends on the log, but a boot failure leaves no notice).

### P3 — low

**P3-1** `.gitignore` ignores `/tests` while its own comment says the suite is committed; `phpunit.xml` and `tests/` are tracked, so the ignore line is inert but misleading. Tests remain paused per §6.
**P3-2** `.audit/` appears twice in `.gitignore` (two separate blocks).
**P3-3** `LeadController::index()` returns hardcoded sample rows; `LeadController::NOTES_CAPABILITY` is a capability invented for the demo. Known from D-2026-11-16-10 ("separate defect, out of scope") — still the visible REST surface for leads.
**P3-4** `SlugGenerator` lives in `core/Shared/Infrastructure/Utilities/` with no `SlugGeneratorContract`, while `SlugCheckerContract` exists; 02 §2.3 names a pair.
**P3-5** 24 tracked files and several untracked files remain uncommitted across `Company/06`–`Framework/02`, including the breaking `ResponseInterface::data()` change (D-09). One disk event loses a dozen verified steps; the trail is complete but the code is not.

## 5 What is compliant (verified, not assumed)

- **Zone boundaries in Core:** zero WP functions, zero `Framework\`, zero `App\` in any `Domain`/`Application` file (only `LeadReadRepository` breaks the Infrastructure boundary).
- **Rich domain model:** every entity is `readonly class` except `ActivityEntity` (P2-5); no setters; behaviour returns new instances (`CompanyEntity::applyChanges()` guards the whole resulting state and returns a new entity); named constructors (`create()`) and `reconstitute()`-style mappers.
- **CQRS split:** separate write/read ports per module, `WpCompanyWriteRepository extends BaseWriteRepository` / `WpCompanyReadRepository extends BaseReadRepository`, no read method on the write port; `AbstractWpRepository` holds only `db()`, `tableName()`, `escapeIdentifier()` exactly as D4 states.
- **Transactions:** every `Company` and `Lead` command service funnels through one `executeInTransaction()`; failure-after-write is a throw, guards return `Result::failure`, events are released before persist and dispatched after `run()` returns.
- **Duplicate keys:** translated in the adapter to a Shared/domain exception (`LeadDuplicateException`, `CompanyDuplicateException`); no vendor exception reaches a service.
- **Delivery:** controllers are thin (no `$wpdb`, no rules, no repository); every write route carries `nonce:` middleware, every admin/REST surface carries a capability middleware (`RequireCompanyManage` reads the admin controller's constant); views escape with `esc_*` and contain no queries; one documented `try/catch` in `Api\CompanyController::index()` maps a mapper `InvalidArgumentException` to 422.
- **Security:** every SQL statement in the Company and Lead adapters is `$wpdb->prepare()`d; identifiers are backtick-escaped in the bases; the only unbound parts are column lists/`ORDER BY` settled in code; `uninstall.php` is guarded by `WP_UNINSTALL_PLUGIN`.
- **Syntax:** `php -l` clean across 348 files.
- **Framework boundary:** `framework/` imports no `Core\`/`App\` class (only the rebrander's string patterns and stubs mention them).
- **REST unwrap:** `Framework/02` is correct — the live `Router` path unwraps `ResponseInterface`; the Company endpoints return a body, not `{}`.

## 6 Verification log

| Run | Result |
| --- | --- |
| `git check-ignore -v .claude/rules .claude/skills docs/ai-reports` | no output, exit 1 → PASS |
| autoload scan of 1001 `use` statements in `core/ app/ routes/ config/` | 16 unresolvable FQCNs (P0-2) |
| `class_exists()` per suspect adapter | 2 fatal declarations (P0-3); 3 load but are Eloquent-backed |
| `php -l` over `core app routes config views` | 0 syntax errors |
| grep `App\Models`/`Illuminate\` in `core/` | 20 files |
| grep `use Framework` in `core/` | 1 file |
| grep `beginTransaction` outside adapters | `ActivityCommandService` only |
| grep contracts bound in `app/Providers` | 11 distinct of 65 |
| grep unescaped echoes in `views/` | 0 |

## 7 Recommended sequence (not executed — each needs its own authorized step)

1. **Stop the bleeding (P0-3).** Remove or park the two fatal classes and the eight missing `Core\User`/`App\*` references; a `composer dump-autoload` + class-load sweep should be part of every port step from now on. Smallest change, no design decision.
2. **Choose the direction for the seven unported modules (OPEN decision D-2026-11-16-11).** Port them one at a time (`Company` as the template) or delete them from the tree until they are ported. Both are legitimate; the current state — half-ported code that autoloads — is not.
3. **Shared kernel first (P0-4, P1-1, P1-3).** Add the six `Wp*` adapters + `ClockContract`/`SystemClock`, move the three app adapters into `core/Shared/Infrastructure/`, bind them in `AppServiceProvider`, and delete the `Laravel*` duplicates.
4. **Module providers (P1-2)** — one `<Module>ServiceProvider` per module as it is ported, listed in `config/app.php`.
5. **Lead module cleanup (P1-4/P1-5/P2-2/P2-3)** — move the read side onto `BaseReadRepository`, drop the fluent builder, rename `ContactService`, inject mapper contracts, and add the missing `lang/*` files (P2-7).
6. **Debt items (P2-6, P3-1…P3-5)** — collapse the duplicate router, fix `.gitignore` comments, commit the working tree.

## 8 Not verified / out of scope

- No live WordPress request was executed; no browser check of the Company screens.
- No database was touched; migrations were read, not run.
- `framework/` was read for boundary checks only — its internals were not reviewed (JOB F, separate owner).
- Performance (query plans, indexes) was not measured; the DB Optimizer lens is a separate pass.
- The `Company` module's own open items from `Company/02` were re-checked only at the level of "still wired", not re-audited line by line.

## 9 Proposed AGENTS.md change

None required — every P0/P1 finding is already forbidden by an existing rule. Two step-authoring notes would prevent the class of defect found here:

- *"After any change to a shared abstract base (`BaseWriteRepository`, `BaseReadRepository`, `AbstractWpRepository`), run a class-load sweep over every subclass — including unported ones — because a signature change in a base turns a dormant file into a fatal autoload."*
- *"A porting step's acceptance must include an autoload scan for unresolvable `use` targets; `php -l` does not catch a class that loads but references a namespace that does not exist."*
