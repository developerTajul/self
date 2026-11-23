---
name: lead-module-scaffolding
description: Lead module scaffolding per AGENTS.md and .claude/rules/03-module-scaffolding.txt - COMPLETE AND VERIFIED
metadata:
  type: project
---

Lead module scaffolding completed and fully verified.

## Files Created (14 files, all verified)

### Domain Layer
- `core/Lead/Domain/Entities/LeadEntity.php` - readonly class with constructor promotion, all schema fields, TODO markers for behaviour methods, empty `domainEvents()` and `releaseEvents()`
- `core/Lead/Domain/Contracts/LeadRepositoryContract.php` - blank interface, no method signatures

### Application Layer
- `core/Lead/Application/Contracts/LeadQueryContract.php` - read port interface
- `core/Lead/Application/Contracts/LeadCommandServiceContract.php` - write port interface
- `core/Lead/Application/Contracts/LeadQueryServiceContract.php` - query service interface
- `core/Lead/Application/DTOs/Responses/LeadResponseDTO.php` - final readonly DTO with promoted constructor, snake_case wire format
- `core/Lead/Application/Mappers/LeadResponseMapper.php` - mechanical entity→DTO mapping, skips id/timestamps
- `core/Lead/Application/Services/LeadCommandService.php` - final class, constructor injection (repository, mapper, result, event dispatcher, logger, transaction manager)
- `core/Lead/Application/Services/LeadQueryService.php` - final class, constructor injection only (repository, mapper, logger) — **no write-side ports**

### Infrastructure Layer
- `core/Lead/Infrastructure/Mappers/LeadEntityMapper.php` - `toEntity()` and `toPersistenceArray()`; persistence array skips id/timestamps, unwraps enums with `->value`
- `core/Lead/Infrastructure/Repositories/WpLeadWriteRepository.php` - extends `BaseWriteRepository`, implements `LeadRepositoryContract`
- `core/Lead/Infrastructure/Repositories/WpLeadReadRepository.php` - extends `BaseReadRepository`, implements `LeadQueryContract`

### Composition Root
- `app/Providers/LeadServiceProvider.php` - binds all module ports: WriteRepo→WpLeadWriteRepository, Query→WpLeadReadRepository, CommandService→LeadCommandService, QueryService→LeadQueryService; plus mapper and service bindings

### Config
- `config/app.php` - `LeadServiceProvider` registered in providers array after `CompanyServiceProvider`

## Verification Results

| Check | Result |
|---|---|
| `composer dump-autoload` | ✅ Success (1812 classes) |
| All `php -l` checks | ✅ All 14 files pass |
| Entity `readonly class` (not `final`) | ✅ Verified |
| Blank `LeadRepositoryContract` interface | ✅ Verified |
| Separate Command/Query services | ✅ Verified |
| Query service has no write-side port injection | ✅ Verified |
| `toPersistenceArray()` skips id/timestamps, unwraps enums with `->value` | ✅ Verified |
| `declare(strict_types=1)` on all files, namespaces `Core\Lead\...` | ✅ Verified |
| No Shared ports re-invented | ✅ Verified |
| No business logic / use-case methods / Request DTOs at scaffold | ✅ Verified per "Scaffold ONLY" constraint |

## Completion

The Lead module scaffolding is fully complete and verified per `.claude/rules/03-module-scaffolding.txt` and `AGENTS.md`. All structural skeletons are in place. Business rules, behaviour methods, use-case DTOs, and Request Mappers are deferred per the "Scaffold ONLY — no business logic, no use-case methods, no Request DTOs or Request Mappers yet" constraint and can be added later with corresponding use cases.