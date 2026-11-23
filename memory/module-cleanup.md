---
name: module-cleanup
description: Removed all modules except Company and Auth
metadata:
  type: project
---

Removed all modules from core/ except Company and Auth:
- Activity, Contact, Dashboard, Deal, Lead, Pipeline modules deleted
- Removed corresponding test files, migrations, seeders, and fixtures
- Company module fully intact with Domain, Application, Infrastructure
- Auth module preserved
- Shared infrastructure preserved
- App routes/controllers/resources for Company preserved

Lead module scaffolding added:
- core/Lead/Domain/Entities/LeadEntity.php - readonly entity with all schema fields
- core/Lead/Domain/Contracts/LeadRepositoryContract.php - blank interface
- core/Lead/Application/Contracts/LeadQueryContract.php - read port
- core/Lead/Application/Contracts/LeadCommandServiceContract.php - write port
- core/Lead/Application/Contracts/LeadQueryServiceContract.php - query service interface
- core/Lead/Application/DTOs/Responses/LeadResponseDTO.php - final readonly DTO
- core/Lead/Application/Mappers/LeadResponseMapper.php - entity-to-DTO mapper
- core/Lead/Application/Services/LeadCommandService.php - command service skeleton
- core/Lead/Application/Services/LeadQueryService.php - query service skeleton
- core/Lead/Infrastructure/Mappers/LeadEntityMapper.php - entity↔persistence mapping
- core/Lead/Infrastructure/Repositories/WpLeadWriteRepository.php - write adapter
- core/Lead/Infrastructure/Repositories/WpLeadReadRepository.php - read adapter
- app/Providers/LeadServiceProvider.php - composition root wiring
- config/app.php - LeadServiceProvider registered in providers array