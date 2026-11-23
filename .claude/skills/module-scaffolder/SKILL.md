---
name: module-scaffolder
description: Scaffolds a new DDD module under core/ (Rich Domain Entity, enums, ports, Command/Query Service skeletons, mappers, write/read repository skeletons, composition-root binding) from a provided database schema. Use when asked to scaffold or initialise a new module. Does not implement use cases.
---

# DDD Module Scaffolder Skill (Lean Architecture Scaffold)

## Description

This skill scaffolds a new Domain-Driven Design (DDD) module using the architectural rules defined in the project's `AGENTS.md` and `.claude/rules/`. It generates the baseline domain constructs and empty structural skeletons, and defers request-specific DTOs, request mappers, and use-case methods until those features are explicitly built.

> **This work follows the Rich Domain Model.** The Entity owns the business rules (they are added with each use case, not at scaffold time), Services are orchestrators, and the Service layer is split into a Command Service (write) and a Query Service (read). Read `AGENTS.md` and `.claude/rules/03-module-scaffolding.txt` before writing code. That file holds the artifact templates and is the authority if anything here seems to differ.

> **Autonomous runs:** when this skill is executed as a plan step (Autonomous Execution Mode), also follow `.claude/rules/09-autonomous_execution.txt`: put the verification checklist into the step report's Self-check table with evidence, write the step report, and record decisions in `docs/ai-reports/DECISIONS.md`. Where this skill says to stop and ask or tell the developer, apply 09 instead: additive Shared additions and the wiring files are in scope (A4) and are done and recorded, and only the hard stops in A10 halt the run.

**Placeholders used in this skill:**

| Placeholder    | Meaning                               | Example                        |
| -------------- | ------------------------------------- | ------------------------------ |
| `{ModuleName}` | PascalCase module name                | `Category`, `Product`, `Brand` |
| `{module}`     | lowercase/plural route & path segment | `categories`, `products`       |

## Context & Rules

- **Source of Truth:** You MUST follow `AGENTS.md` in the project root and the rule files it indexes. Derive every field from the PROVIDED schema/migration. If no schema is provided, ask for it. Never invent or omit columns.
- **Framework Agnostic:** The core `Domain` and `Application` layers must not contain any framework-specific helpers, facades, or HTTP dependencies. The only framework-aware layer is `Infrastructure`. Ports use the `...Contract` suffix.
- **Non-Destructive:** If a file or module folder already exists, do not overwrite it. Stop and report what already exists.
- **Empty-Skeleton Guard:** Contracts, Services, and the Repository are empty skeletons. No use-case methods, no business logic, no behaviour methods on the Entity at scaffold time.
- **Just-In-Time Separation:**
    - Scaffold ONLY the common baseline: Entity, Enums (if the schema has them), Domain Exception, Repository Contract, ResponseDTO, ResponseMapper, Command Service, Query Service, Repository, EntityMapper.
    - Do NOT generate Request DTOs (CreateDTO, UpdateDTO), the Request Mapper (`{ModuleName}Mapper`), or `{ModuleName}MapperContract`. They are built later with their matching features.
    - Do NOT generate `Domain/DataObjects/`. It is legacy; new modules pass Entities between Service and Repository, and the EntityMapper converts Entity to table array.
    - Do NOT generate `Domain/Events/` or `Application/Jobs/`. They are created only when a use case raises an event or needs queued work.

## Execution Steps

1. **Pre-checks (read only):**
    - Read `AGENTS.md`, `03-module-scaffolding.txt`, and `.claude/rules/02-framework-adapter-wordpress.txt` (the WordPress adapter, config, DI and `$wpdb` rules for the EntityMapper and Repository).
    - Confirm `core/{ModuleName}/` does not already exist.
    - Check which Shared pieces exist: `TransactionManagerContract`, `EventDispatcherContract`, `SlugCheckerContract`, `SlugGeneratorContract`, `FileUploaderContract`, `AbstractWpRepository`, `BaseWriteRepository`, `BaseReadRepository`, `Result`, `PaginatedResult`, and the `SlugGenerator` utility (with its `SlugGeneratorContract`). Reuse what exists. For a Shared port the module needs that has no adapter yet, follow the Adapter Co-Generation Mandate in `03`: create the contract and its adapter, and bind it once in the composition root. Never re-invent an existing Shared building block.

2. **Directory Generation:** Create the module tree inside `core/` (no `DataObjects/`, `Events/`, `Jobs/`, or `Application/DTOs/Requests/` yet):

    ```
    core/{ModuleName}/
    +-- Domain/
    |   +-- Entities/{ModuleName}Entity.php
    |   +-- Enums/                          (only if the schema has enum/state columns)
    |   +-- Contracts/{ModuleName}RepositoryContract.php      (WRITE port)
    |   +-- Exceptions/{ModuleName}NotFoundException.php
    +-- Application/
    |   +-- Contracts/{ModuleName}QueryContract.php           (READ port)
    |   +-- Contracts/{ModuleName}CommandServiceContract.php
    |   +-- Contracts/{ModuleName}QueryServiceContract.php
    |   +-- DTOs/Responses/{ModuleName}ResponseDTO.php
    |   +-- Mappers/{ModuleName}ResponseMapper.php
    |   +-- Services/{ModuleName}CommandService.php
    |   +-- Services/{ModuleName}QueryService.php
    +-- Infrastructure/
        +-- Repositories/Wp{ModuleName}WriteRepository.php     (extends BaseWriteRepository)
        +-- Repositories/Wp{ModuleName}ReadRepository.php      (extends BaseReadRepository)
        +-- Mappers/{ModuleName}EntityMapper.php
    ```

    Two ports, two adapters, never one shared repository. The WRITE port exchanges pure Entities and declares only writes plus `findByIdForUpdate(int $id)`; listings and lookups go on the READ port. One adapter never implements both ports.

3. **Entity Generation (`Domain/Entities/`):**
    - Create the Entity as a PHP 8 `readonly class`. Do NOT add `final` - `final` is for Services, Mappers, Adapters and Controllers, not Entities.
    - Implement `__construct()` with constructor property promotion, one property per schema column. Types match the schema exactly (nullable columns become `?type`, enum columns become the Domain enum), with one exception: the primary key `id` is ALWAYS `?int` (null until persisted). The ResponseDTO keeps `int $id` and the ResponseMapper casts `(int) $entity->id`.
    - Do NOT add `private array $domainEvents = []` or `releaseEvents(): array` on a new Entity that raises no events. Both are added together with the FIRST behaviour method that raises a Domain Event (03, 7.1.1 DOMAIN EVENTS).
    - Do NOT add behaviour methods, named constructors, or business rules. Leave a `// TODO:` marker saying they are added with the use cases.
    - No framework import, no I/O.

4. **Enum Generation (`Domain/Enums/`):**
    - If the schema contains limited state or choice fields (e.g., status, type), generate the corresponding Enum, with cases mirroring the schema's values.
    - MUST implement a `translationKey(): string` method returning a stable lang key for each case (e.g. `'lead.status.pending'`).
    - MUST NOT implement a `label()` method returning hardcoded human-readable strings - Core carries no display text (AGENTS.md - LOCALIZATION MANDATE).
    - MUST implement a static `values()` method returning an array of raw values via `array_column(self::cases(), 'value')`.

5. **Contract and Exception Generation:**
    - `{ModuleName}RepositoryContract` MUST be a blank interface with no method signatures. Methods are declared by the use case that needs them.
    - `{ModuleName}NotFoundException` follows the template in `03`.

6. **Application Layer Generation:**
    - `{ModuleName}ResponseDTO`: a `readonly class` with promoted properties of plain PHP types, mapped from the Entity's presentation-safe fields.
    - `{ModuleName}ResponseMapper`: a `final class` with `toResponseDTO(Entity): ResponseDTO`, a mechanical mapping.
    - `{ModuleName}CommandService` and `{ModuleName}QueryService`: `final` classes that `implements {ModuleName}CommandServiceContract` / `{ModuleName}QueryServiceContract` respectively, with constructors and dependency injections only (constructor property promotion, no assignments in the body), and no methods.
        - Command Service constructor: repository contract, response mapper, `TransactionManagerContract`, `EventDispatcherContract`, logger, plus `SlugGeneratorContract` (implemented by the Shared `SlugGenerator`, which carries `SlugCheckerContract`) if the schema has a `slug` column and `FileUploaderContract` if it has a thumbnail/image/file column. Add `QueueManagerContract` only if the module needs queued work.
        - Query Service constructor: repository contract, response mapper, logger only. No transaction manager, event dispatcher, slug, or upload ports. `TranslatorContract` is appended later, only when a use case produces user-facing messages (05 R12.6).
        - Also scaffold the two INBOUND CONTRACT interfaces in `Application/Contracts/` as empty skeletons (`// TODO:` markers).

7. **Infrastructure Layer Generation:**
    - `Wp{ModuleName}WriteRepository`: a `final class` extending `BaseWriteRepository` and implementing `{ModuleName}RepositoryContract`, with a constructor taking the injected `{ModuleName}EntityMapper` plus `?wpdb $wpdb = null` (passed to `parent::__construct($wpdb)`; the handle resolves lazily, see `03`). No methods.
    - `Wp{ModuleName}ReadRepository`: a `final class` extending `BaseReadRepository` and implementing `{ModuleName}QueryContract`, same constructor shape. Implement required query-port methods; use shared `findRecordById()` and `rowExists()` where they match the operation. `BaseReadRepository` inherits no write method, so the CQRS boundary holds by inheritance.
    - Both declare the table suffix as a private constant (e.g. `private const TABLE_SUFFIX = '{module}';`) and pass it per call to the base helpers.
    - `{ModuleName}EntityMapper`: `toEntity(array $row)` (the `ARRAY_A` row to Entity) and `toPersistenceArray(Entity)` (Entity to table array). Both are mechanical schema mappings and are fully implemented. `toPersistenceArray()` skips the primary key and auto-managed timestamps, and unwraps enum properties with `->value`.
    - Any placeholder method that infrastructure constraints force you to declare must throw `\LogicException('Method not implemented')`.

8. **Readability Standards for Array Manipulations:**
    - Always separate array definitions from functional operations (such as `array_filter` or `array_map`).
    - When a method returns a filtered array, first declare a local variable (e.g., `$data`) holding the complete associative array. Then apply the functional operation in a separate return statement (e.g., `return array_filter($data, ...)`).
    - Never inline large arrays directly inside function calls.
    - In Repository code, rows arrive from `$wpdb` as `ARRAY_A` arrays (`rows` = `list<array>`, `row` = `array|null`). Map them with native `array_map()`/`array_filter()`. There is no Eloquent Collection in this project: never call `collect()`, `->map()->all()`, or any `Illuminate\` helper.

9. **Composition Root Wiring (AGENTS.md P1):**
    - Bind the module's own ports in `app/Providers/{ModuleName}ServiceProvider.php` (NOT `AppServiceProvider`), and list that provider in the `providers` array in `config/app.php`. Bind four ports: `{ModuleName}RepositoryContract` -> `Wp{ModuleName}WriteRepository`, `{ModuleName}QueryContract` -> `Wp{ModuleName}ReadRepository`, `{ModuleName}CommandServiceContract` -> `{ModuleName}CommandService`, `{ModuleName}QueryServiceContract` -> `{ModuleName}QueryService`.
    - Shared ports (`EventDispatcherContract`, `QueueManagerContract`, `TransactionManagerContract`, `TranslatorContract`, `SlugGeneratorContract`, `SlugCheckerContract`, `FileUploaderContract`, `\wpdb`) belong in `AppServiceProvider` and are bound ONCE, reused by every module. If one is missing, bind it there; never rebind a Shared port per module and never alter an existing binding.

10. **Formatting:** `declare(strict_types=1);` at the very top of EVERY generated PHP file, correct PSR-4 namespaces (`Core\{ModuleName}\...`), `final` on implementation classes where applicable. DocBlocks are short and plain (1-2 lines, e.g. "Handles {module} write use cases."), with `@implements` carrying the FQCN where a class implements an interface. After adding files under a new namespace, tell the developer to run `composer dump-autoload` (run it yourself only if the prompt allows terminal commands).

## Testing

Unit tests are paused (`AGENTS.md` - UNIT TESTING POLICY). No tests are generated at scaffold time or with later use cases until the developer re-enables testing.

## Verification Checklist (fill in only from what the generated code actually contains)

Claim only what you can point to in the generated files. A false claim is a defect.

- [ ] Every schema column is mapped, none invented or omitted, and types match the schema exactly.
- [ ] Entity is a `readonly class` (not `final`) with no behaviour methods and no framework import. Integrity: an Entity with no events has NEITHER `$domainEvents` NOR `releaseEvents()`; an event-carrying Entity has both and forwards them. A fresh scaffold has neither.
- [ ] Enum (if any) has `translationKey()` returning stable lang keys and `values()` with `array_column(self::cases(), 'value')`. No `label()`.
- [ ] No `DataObjects/`, `Events/`, `Jobs/`, Request DTO, Request Mapper, or `MapperContract` was generated.
- [ ] `{ModuleName}RepositoryContract` is a blank interface; Services and both Repositories contain constructors and injections only.
- [ ] Both adapters exist and extend the right base: `Wp{ModuleName}WriteRepository extends BaseWriteRepository`, `Wp{ModuleName}ReadRepository extends BaseReadRepository`. Neither extends the other, and the read adapter inherits no write method.
- [ ] Command and Query Services are separate classes; the Query Service injects no write-side ports.
- [ ] `toPersistenceArray()` skips the primary key and timestamps and unwraps enums with `->value`.
- [ ] `declare(strict_types=1);` is the first statement in every PHP file and namespaces follow `Core\{ModuleName}\...`.
- [ ] No file was overwritten; the module provider was created, listed in `config/app.php`, and no existing binding changed.
- [ ] Any new Shared port got its adapter and one binding; no existing Shared artifact was re-invented.
- [ ] `composer dump-autoload` was run or the developer was told to run it.

## Output

Execute the file creation directly using your filesystem tools. Provide a short tree view of the files created, then the filled-in verification checklist. Keep conversational text to a minimum.
