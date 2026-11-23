# AGENTS.md (WordPress edition)
Binding rules. MUST/NEVER are absolute. Detail in `.claude/rules/` (index at end). Keep this file small: load rule files only when their trigger applies.

## 0 STARTUP & PRE-FLIGHT PROTOCOL (run before touching any file)
Both checks run at the start of every task, before mode detection, and their result goes in the report.
- CONTEXT HYDRATION: read the last 3 entries of `docs/ai-reports/DECISIONS.md` and the latest entry of `docs/ai-reports/PROJECT-LOG.md`, plus the previous step's report. An OPEN decision that touches this task binds it: follow it or record why not (A9). Never re-decide a settled question from scratch; never contradict a recorded decision silently.
- GIT TRACKING GUARD: verify `.claude/rules/`, `.claude/skills/` and `docs/ai-reports/` are not ignored — `git check-ignore -v .claude/rules .claude/skills docs/ai-reports` MUST return no output for them. Any of the three ignored is a HALT (09 A10, Structured HALT): rules, skills and the decision trail are the project's memory, and untracked they exist on one machine until it is lost. Nothing else in the task proceeds until it is resolved. `docs/ai-plans/`, `docs/ai-reports/` and `.claude/rules|skills/` MUST stay tracked; unrelated cache/temp paths inside them MAY be ignored individually.
- Missing file from either check = HALT: report the missing path, never create it to pass the check.

## 1 IDENTITY: TWO JOBS, THREE ZONES
JOB F (FRAMEWORK) builds `framework/` (`Framework\`), the Laravel-shaped, WordPress-aware library (future Packagist package). JOB C (CORE) builds framework-free modules in `core/`. Never mix them in one step.
| Zone | Namespace | Knows WordPress | May import | Edited by |
| --- | --- | --- | --- | --- |
| `core/*/Domain`, `core/*/Application`, `core/Shared/{Domain,Application}` | `Core\` | NEVER | PHP built-ins, PSR, other Core Domain/Application | JOB C. FROZEN when porting |
| `core/*/Infrastructure`, `core/Shared/Infrastructure` | `Core\` | YES | Core contracts, WordPress APIs incl. `\wpdb`, PHP, PSR. NOT `App\`, NOT `Framework\` | JOB C. REPLACED when porting |
| `app/`, `config/`, `routes/`, `views/`, `database/` | `App\` | YES | Core contracts/DTOs, `Framework\` | project code (DELIVERY) |
| `framework/` (later `vendor/`) | `Framework\` | YES | PHP, WordPress, PSR, `devlinkstudios/wp-service-container` | JOB F ONLY; read-only otherwise |
- `framework/` NEVER imports `App\` or `Core\`. Adapters never live in `app/`. Rebrand prefixes `App\` and `Core\` (`<Vendor>\Core\`), never flattens; `Framework\` is never rebranded.

## 2 MODE DETECTION (FIRST STEP OF EVERY TASK)
Classify every path the task touches, name the mode in the plan, load only its files.
| Path | Mode | Load | Forbidden |
| --- | --- | --- | --- |
| Core Domain/Application | CORE-PURE | 01, 05, 06 (+08 events, 03 new module, 04 CRUD) | WordPress, `Framework\`, `App\`, Laravel symbols, helpers, facades |
| Core Infrastructure | ADAPTER | 02, 06 | business rules, `App\`/`Framework\` imports, leaking `$wpdb`/WP types upward |
| `app/ routes/ config/ views/ database/` | DELIVERY | 02 | business rules, repositories in controllers, editing `framework/` |
| `framework/` | FRAMEWORK | 10, 02 | `App\`, `Core\`, project names/keys |
- Multi-mode task: split into ordered steps CORE-PURE, ADAPTER, DELIVERY. A module task NEVER edits `framework/` or `vendor/`.
- Needed framework feature missing: HALT (Interactive Alert), name it, wait. Never patch the framework to unblock a module. Reading framework code is always allowed.

## 3 PORT FREEZE
FROZEN = Domain + Application of every module, `core/Shared/Domain`, `core/Shared/Application`. REPLACEABLE = every `Infrastructure/`.
- One source of truth per module (the project the developer names). The other is a mirror: never hand-edit its FROZEN folders; change the source, re-port.
- Porting (any framework <-> this plugin): copy FROZEN folders unchanged; write NEW Infrastructure adapters for the same contracts (`EloquentLeadWriteRepository` -> `WpLeadWriteRepository`), composition root and delivery. Procedure: 06 T9.
- Zero edits in FROZEN files, not one character. If one seems needed, the design leaked a framework detail: tighten a port in the source, re-port.
- Only allowed difference: the vendor namespace prefix on `namespace`/`use` lines, set by the rebrander or `tools/port-audit.sh`, never by hand. Audit (06 T8): with the prefix stripped, FROZEN trees are identical.
- A business rule found in an adapter/delivery file while porting is lifted into the Entity in the SOURCE first (P15), then re-ported.

## 4 LOCKED DECISIONS
- D1 Namespace prefix is the only FROZEN difference, applied by tool.
- D2 Core and Infrastructure never get the `Config` object. Contextual binding (`when()->needs()->give()`) passes scalars only. Only `app/` may use `Config`.
- D3 Release build prefixes `vendor/` and `framework/` (incl. `wp-service-container`) with PHP-Scoper or a Mozart-family tool; source keeps `Framework\`.
- D4 WordPress-specific Infrastructure classes use the `Wp` prefix (`WpLeadWriteRepository`, `WpTransactionManager`). Exception by developer choice: the abstract connection base stays `AbstractWpRepository`, which holds only `db()`, `tableName()` and `escapeIdentifier()` and no read or write method. It is extended by `BaseWriteRepository` (`insert()`, `write()`, `deleteRecord()`, `findRecordForUpdate()`, duplicate handling) and `BaseReadRepository` (currently empty, ready for shared read helpers).
- D5 Core has no user-facing string and never calls `__()`. `WpTranslator` reads `lang/<locale>/<module>.php` by key.
- D6 FLUENT DATABASE IS PAUSED. While paused: write repositories extend `BaseWriteRepository` and read repositories extend `BaseReadRepository` (both over `$wpdb`, 03) and implement their contracts; never import `Framework\Database\*`. Spec for re-enabling: `11-fluent-database.txt` (load only when the developer re-enables).
- D7 Code in `framework/` NEVER calls global helpers (`config()`, `app()`, `view()`, `base_path()`...); it uses DI/container. Helpers are project sugar for `app/`, `routes/`, `config/`, views only. Core and Infrastructure never call them.
- D8 `env()` only inside `config/*.php`. Constructor injection only; no service locator (`app()`, `resolve()`) in Core, adapters or controllers.

## 5 ARCHITECTURE
Hexagonal + Clean + Rich Domain Model. `declare(strict_types=1);` everywhere. `final` on Services, Mappers, Adapters, Controllers; NOT on Entities. Dependencies point inward: Delivery -> Application -> Domain; Infrastructure implements Core ports. Primary goal: `core/` moves unchanged; porting = new adapters + new composition root only.

## 6 BEHAVIOR
- SCOPE: change nothing outside the prompt. If an out-of-scope change seems necessary, HALT with the reason (Autonomous mode: 09 A10).
- FILE EXISTENCE GUARD (highest priority): before creating ANY file, verify it does not exist (`git ls-files <path>` or list the dir). If it exists, editing it is out of scope unless the prompt names it. A second file at an occupied path (duplicate Service/Contract/Mapper) is the worst scope violation.
- INHERENT WIRING (in scope, no halt): bind a new Contract in `AppServiceProvider` (shared) or the module's `<Module>ServiceProvider`; list a new provider in `config/app.php`; register routes for a new controller action. Never covers `framework/`/`vendor/`.
- TESTS PAUSED: never write, scaffold, require or run tests, never create `tests/`, never load 07, never claim tests in a checklist, unless a prompt explicitly asks (that request only). Keep code testable. Only the developer re-enables.
- AUTONOMOUS MODE (developer asks to execute a step/module/plan): load 09 first. Never offer choices; decide by these rules. Plan in `docs/ai-plans/` is read-only; reports in `docs/ai-reports/`. Never edit AGENTS.md, rules, skills or plans unless the step says so; propose changes in the report.
- RULE-TO-SKILL SYNC (binding; procedure 09 A5b): a step that edits AGENTS.md, `.claude/rules/**` or a `SKILL.md` MUST then sweep `.claude/skills/**/SKILL.md` for the terms the edit invalidated — the old names, paths, rule-file references and code shapes, never the new ones — because stale skill text contains the OLD string and a search for the new one returns nothing. Hits inside the step's write scope are fixed in the same step; hits outside it are NOT edited and are reported line by line under a `STALE_SKILLS_DETECTED` heading. A clean sweep is reported as `none` plus the terms swept; no skill may keep instructing an agent to import, extend or route through something this project does not have.
- AUTONOMOUS FIX LOOP (binding; full protocol 09 A6b): a failing self-check is repaired **at most twice** (Round 1, Round 2 after Round 0), never a third. Each failure is identified by its **Failure Signature** — `[error type + exact message] + [file path] + [line number]`, never root cause — and if the SAME signature returns after a repair, HALT immediately (H8) instead of trying again: an unmoved error means the diagnosis is wrong. Every repair is **bounded**: only the method, statement or call that produced the signature, never a class-wide rewrite or an unrelated tidy-up; an architectural fix moves the offending statement to its proper layer and nothing more. A repair that changes an Interface/Contract requires a tree-wide scan for the **bare contract name** (not just `implements`) with `php -l` and an interface signature-compatibility check on every hit. **A10 outranks the loop absolutely**: any H1-H13 halts immediately with 0 repair rounds and is never treated as repairable.
- HALT only for: missing required input; out-of-scope change; needed change in `framework/`/`vendor/`; edit to a FROZEN folder; destructive action; secrets; forbidden commands (migrations, installs, test runs, history rewrites); architecture-breaking decision; failing self-check after two repair rounds; a §0 tracking-guard failure. Every halt uses the 3-part Structured HALT format (09 A10): broken rule quoted, conflicting `path:line` files, 2-3 options with trade-offs.
- VERIFICATION: audit actual files, never claim from memory. Before coding list the applicable rule sections; after coding re-read the code against them. Report failed/partial steps honestly.
- INTERACTIVE ALERT (every HALT/confirmation, Windows): run `powershell.exe -Command "[System.Media.SystemSounds]::Asterisk.Play(); Start-Sleep -Milliseconds 300; [System.Media.SystemSounds]::Asterisk.Play()"` then `powershell.exe -Command "Add-Type -AssemblyName System.Windows.Forms; [System.Windows.Forms.MessageBox]::Show('Action required - please respond', 'Claude Code', [System.Windows.Forms.MessageBoxButtons]::OK, [System.Windows.Forms.MessageBoxIcon]::Question)"`.

## 7 RICH DOMAIN MODEL (SINGLE SOURCE OF TRUTH)
- Business rules, invariants, transitions, derived values live in Entities/Value Objects. Behaviour methods state intent (`$order->cancel()`), no generic setters. Anemic entities are forbidden. A business decision in a Service becomes an Entity method (Tell, Don't Ask).
- Entities are `readonly class` (never `final`), immutable: a behaviour method enforces the rule and returns a NEW Entity. Create via named constructor (`Activity::create()`); rebuild from storage in the Infrastructure EntityMapper (`reconstitute()` or equivalent). Nothing outside the Entity writes its state.
- Entities, VOs, Domain Events import no framework code.
- Repositories take and return Entities; never DTOs/arrays from Service to Repository. Storage rows/models stay in Infrastructure.
- Domain Events: readonly list on the Entity; EVERY behaviour method forwards existing events and appends new (`domainEvents: [...$this->domainEvents, new XEvent()]`). `releaseEvents()` returns, never clears. The Command Service releases events from the behaviour-method Entity BEFORE persisting, dispatches once via the port after commit. Entity never dispatches. Full rules: 08.

## 8 SERVICE LAYER (CQS)
Services orchestrate; no business rules. A Service is Command OR Query.
- Command `<Module>CommandService`: DTO -> load/create Entity via WRITE port -> Entity behaviour -> persist -> dispatch released events -> `Result<ResponseDTO>`. All writes run in a transaction (section 10). Never returns lists.
- Query `<Module>QueryService`: reads only (no transaction, events, writes); depends on the READ port `<Module>QueryContract`, never the write port; returns ResponseDTOs/ProjectionDTOs, never Entities/rows. List input = `<Module>FilterDTO` clamped via a Domain `<Module>Rules` class in the Request Mapper. Details: 05 R12.
- INBOUND CONTRACTS: every module exposes `<Module>CommandServiceContract` and `<Module>QueryServiceContract` (`Application/Contracts/`); delivery injects the contract, never the concrete Service. Narrower query ports are additive.
- ResponseDTOs: `final readonly`, plain types, promoted constructor. Failures via `Result::success($dto)` / `Result::failure($message, $errorCode)`. Formatting for JSON/view/CLI is delivery's job; delivery is thin: validate -> DTO -> one Service call -> translate Result.

## 9 CQRS PORT SPLIT
| | Write | Read |
| --- | --- | --- |
| Port | `<Module>RepositoryContract` in `Domain/Contracts/` | `<Module>QueryContract` in `Application/Contracts/` |
| Adapter | `Wp<Module>WriteRepository extends BaseWriteRepository` | `Wp<Module>ReadRepository extends BaseReadRepository` |
| Used by | Command Services | Query Services, delivery |
- The write port declares no pure read; only `findByIdForUpdate()` (row lock before the save). A read adapter extends `BaseReadRepository`, never a write repository, and `BaseReadRepository` inherits no write method, so the boundary holds by inheritance rather than by discipline. A class never implements both ports. Extra read surfaces get their own small port.
- Port method names describe the domain operation (`findById`), never a class shape.

## 10 TRANSACTIONS (MANDATORY)
Every write or read-then-write runs in `TransactionManagerContract::run(callable): mixed` (begin, run, commit on return, rollback + rethrow on throw). Query Services never open one. `WpTransactionManager` (`core/Shared/Infrastructure/Database/`, `$wpdb`) is the only adapter; one nesting rule, never a second implementation in `app/`. CPT modules use `CompensatingActionTransactionManager`.
```php
$events = [];
try {
    $result = $this->transactionManager->run(function () use ($dto, &$events) {
        // lookup (findByIdForUpdate for read-then-write) -> Entity behaviour (rule violation throws)
        // -> $events = $entity->releaseEvents(); $this->repository->update($entity);
        return Result::success(data: $responseDTO, message: '...');
    });
} catch (SomeDomainException $exception) { // already rolled back
    return Result::failure($exception->getMessage(), 'DOMAIN_ERROR_KEY');
} catch (\Throwable $exception) { // log, clean up new file, generic failure Result
}
// committed: dispatch $events, push jobs, delete replaced files
return $result;
```
- Never `beginTransaction()/commit()/rollback()` in a Service. Closure: `use ($x)`, `use (&$x)` only for values needed after `run()`.
- A failure after a write is signalled by THROWING (a swallowed Throwable plus `Result::failure()` inside the closure would COMMIT partial work). `Result::failure()` inside the closure only for guards before any write.
- After-commit work (events, jobs, file deletes) runs after `run()` returns.
- DRY: put `run()` and the shared catch blocks in ONE private `executeInTransaction(callable $operation, string $logMessage, string $fallbackMessageKey, string $fallbackErrorCode, array $context = []): Result`. Legacy manual flow is forbidden for new code.
- A write without a transaction is a critical violation; flag it in every review.

## 11 PORTS AND ADAPTERS
Everything Core needs from outside is a `...Contract` interface in Core, implemented outside it (create only the ports the task needs). Core never uses facades, helpers or globals (`event()`, `dispatch()`, `now()`, `Storage::`, `do_action`, `wp_*`). Events and jobs are plain PHP. Adapters hold no business logic and leak no framework types. Contracts: repositories, `EventDispatcherContract` (`WpEventDispatcher`), `QueueManagerContract` (`WpQueueManager`), `TransactionManagerContract`, `TranslatorContract` (`WpTranslator`), file storage, clock, logger. Table of adapters: 02 section 2.3, 06 T2.
- NO VENDOR EXCEPTIONS IN CORE: a Service never imports, catches or type-hints a persistence/vendor exception. Infrastructure translates a duplicate-key failure to `Core\Shared\Domain\Exceptions\DuplicateKeyException` (or the module's conflict exception). Uniqueness is race-safe via a UNIQUE index.
- Infrastructure converts storage <-> Entities via the EntityMapper; Core never depends on a persistence shape. Infrastructure failures become `\RuntimeException`; silent failures are forbidden.
- Core has no hardcoded user-facing text; text reaches it only via `TranslatorContract`; error codes stay untranslated keys.
- Infrastructure strings (storage dirs etc.) are private constants at the top of the Service.

## 12 P-RULES
- P1 Each module binds its own ports in `app/Providers/<Module>ServiceProvider.php`, listed in `config/app.php`; `AppServiceProvider` holds shared ports only.
- P2 Money is a `string`, never `float`; format to the column scale once in the Request Mapper.
- P3 A field derived from a state transition is never client input.
- P4 "Unchanged" vs "clear": explicit boolean per clearable field; `null` = unchanged.
- P5 Seeded lookup rows are matched by stable natural key, not surrogate ID; record in `DECISIONS.md`.
- P6 A SUM across mixed currencies groups by currency or states the single currency.
- P7 A module reporting on another module's tables declares its own read port/adapter; never widen another module's port.
- P14 Delivery resolves the current user and passes it in the DTO; Core never reads session/auth/request state.
- P15 A business rule that exists only in delivery code is lifted into the Entity UNCHANGED; note it in `DECISIONS.md`.
- P16 A ResponseDTO property that reaches the client unconverted keeps the wire casing (usually `snake_case`); one DTO, one casing; otherwise `camelCase`.

## 13 STYLE
- Docblocks: short, technical, 1-2 lines, plain verbs ("Handles category creation"); `@implements <FQCN>` on implementers; keep `@param/@return`. No noise comments (explain WHY only). No commented-out code.
- Constructor promotion: no property declarations above, no assignments in the body: `public function __construct(private readonly Dep $dep) {}`.
- Guard clauses / early return; no nested conditions or complex ternaries.
- Naming: contracts end `...Contract` (no `I`/`Interface`); one type per file; semantic variable names, no abbreviations.

## 14 COMPLETION CHECKLIST (audit the code, never claim from memory)
[ ] §0 ran: recent `DECISIONS.md`/`PROJECT-LOG.md` read; `git check-ignore -v .claude/rules .claude/skills docs/ai-reports` printed nothing for them.
[ ] A rule/AGENTS.md/skill edit was followed by the A5b sweep, reported as a `STALE_SKILLS_DETECTED` table or an explicit `none`.
[ ] Any self-check repair followed 09 A6b: at most 2 rounds, no repeated Failure Signature, bounded to the offending statement, contract changes scanned tree-wide, no A10 stop bypassed — each round's signature and outcome reported.
[ ] Mode named; `framework/`/`vendor/` untouched outside JOB F.
[ ] 06 T8 greps return zero in Core Domain/Application/Infrastructure; FROZEN folders unchanged by porting (`tools/port-audit.sh`).
[ ] No `env()` outside `config/`; no `Config` in Core/Infrastructure.
[ ] Rules and transitions in the Entity; Entities `readonly class`, not `final`.
[ ] Service is Command OR Query; returns ResponseDTO in `Result`, never an Entity/row.
[ ] Every write in `run()` via `executeInTransaction()`; events dispatched after commit.
[ ] Write port -> `Wp<Module>WriteRepository extends BaseWriteRepository`; read port -> `Wp<Module>ReadRepository extends BaseReadRepository`; no pure read on the write port.
[ ] Delivery injects inbound service contracts; nonce + capability checked; every DB value prepared (02 s7).
[ ] No vendor exception in a Service; duplicate key arrives as a Shared Domain exception.
[ ] Each Contract bound once (shared in `AppServiceProvider`, module in its provider).
[ ] Non-existence of every created file was verified.

## 15 RUNTIME DIAGNOSTICS & LOG WATCHER
Binding when investigating a runtime error in `wp-content/debug.log` (skill: `.claude/skills/log-watcher/`).
- THE LOG IS EVIDENCE OF A FAILURE, NOT OF A RULE BREACH. **The log identifies the failure; the rule matrix identifies where to investigate; the source code confirms whether a rule was actually violated.** Never report a rule as violated on log output alone.
- MANDATORY PIPELINE, in order: (1) Log Analysis — locate the block with `grep -n -C`, read the full stack trace; (2) SCOPE GATE — does any frame point into `app/`, `core/`, `framework/` or `routes/`? If not, the entry is OUT OF SCOPE (third-party plugin, WP core, `vendor/`, environment) or a STALE ENTRY, and no rule of ours can be cited; (3) Mapping — name the **Primary Investigation Rule** and its confidence, never a verdict; (4) Source Inspection — open the real file at the exact frame and quote the lines; (5) Confirm Violation — only now, and `NO VIOLATION` and `INCONCLUSIVE` are valid outcomes. Skipping the gate or the source read is the failure this section exists to prevent.
- LOW-SEVERITY ENTRIES ARE CONTEXT, NOT NOISE: read Warnings and Notices around a fatal; a warning immediately before a fatal is frequently its cause. Severity ranks effort, never evidence.
- THE LOG PATH IS RELATIVE TO ITS CWD (`wp-content/debug.log` from the WP root, `../../debug.log` from this plugin's root). Confirm the path resolved to a real file before concluding the log is clean — an empty result from a wrong path is the commonest false negative.
- LOG ENTRIES GO STALE: verify the cited rule file exists and the quoted line still matches before reporting. A stack frame pointing at code that has since changed is a stale entry, not a finding.
- READ-ONLY: diagnosing never edits the code it finds, and never deletes, truncates or rotates the log. A fix is a separate, requested step.
- Report in the strict 4-part structure: Log Evidence / Primary Investigation Area / Source Inspection Finding / Confirmed Violation & Compliant Fix.

## A17 SPECIALIZED PERSONAS & GOVERNANCE
- **Persona handover:** When work moves between the Domain Specialist, DB Optimizer, and Security Auditor review lenses, provide a brief **Handover Summary** in chat: scope, reviewed/touched files, findings, and unresolved questions. Do not imply a separate agent reviewed work unless that review occurred.
- **Conflict resolution:** Resolve competing recommendations in this order: **Security > Correctness & Data Integrity > Purity > Performance**. Apply the highest-priority concern while preserving all other requirements where possible; record material trade-offs under §6 / rule 09 A9.
- **Core principle:** Security must never be compromised for performance, and performance must never dictate domain architecture.
- **Security veto:** The Security Auditor may veto an affected change only for a concrete, evidence-backed vulnerability or an unresolved security condition that makes the change unsafe. A veto must state the finding and failure scenario, cite source and rule, give the smallest in-scope remediation, and require re-audit of the repaired code and scenario before completion. A veto does not authorize scope expansion or override §6 hard stops / rule 09 A10; report a blocker when remediation requires forbidden or out-of-scope work. See rule 14.
- Persona lenses supplement, and do not replace, the applicable project rules and mode boundaries. See rules 12–14 for their review responsibilities.

## RULE FILE INDEX (`.claude/rules/`)
01 universal philosophy (always) | 02 WordPress adapter, config, DI, `$wpdb`, security (app/, config, adapters, porting) | 03 new-module scaffolding | 04 CRUD pipeline | 05 Entities, Services, DTOs | 06 ports, porting, audit | 07 testing (PAUSED, on request only) | 08 events and queues | 09 autonomous execution (always when executing a plan) | 10 framework package (JOB F only) | 11 fluent database (PAUSED, on request only) | 12 Domain Specialist | 13 DB Optimizer | 14 Security Auditor
