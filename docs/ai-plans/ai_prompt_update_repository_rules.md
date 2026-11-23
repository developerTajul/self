# Action Plan & Prompt: Refactor Repository Inheritance Rules

**Role:** Senior Software Architect
**Task:** Update the architectural rules across the documentation to implement a new `AbstractWpRepository` hierarchy, relaxing the "extends NOTHING" constraint for Read Repositories while maintaining strict CQRS boundaries.

## 1. Context & Architectural Shift
Currently, the rules mandate that `Wp<Module>ReadRepository extends NOTHING` to prevent read repositories from inheriting write capabilities (insert/update/delete) from a monolithic `BaseRepository`. 

We are refactoring the base repository layer into three distinct classes:
1. `AbstractWpRepository`: Contains only database connection resolution (`db()`), prefixing (`tableName()`), and SQL escaping/quoting utilities. No read or write methods.
2. `BaseWriteRepository extends AbstractWpRepository`: Contains `insert()`, `write()`, `deleteRecord()`, `findRecordForUpdate()`, and duplicate exception handling.
3. `BaseReadRepository extends AbstractWpRepository`: Contains read-only shared utilities like `paginateRecords()` and `fetchAllLatest()`.

Module repositories will now map as follows:
* `Wp<Module>WriteRepository extends BaseWriteRepository`
* `Wp<Module>ReadRepository extends BaseReadRepository`

## 2. Execution Instructions for AI Assistant
Please search for and update the following rule files in the workspace. Maintain the existing tone and format, just update the specific repository inheritance constraints.

### Target 1: `AGENTS.md`
* **Section 9 (CQRS PORT SPLIT):** 
  * Change "Adapter" Read column from `Wp<Module>ReadRepository extends NOTHING` to `Wp<Module>ReadRepository extends BaseReadRepository`.
  * Update the text below the table: Change "A read adapter never extends `BaseRepository` or can write" to "A read adapter extends `BaseReadRepository`, never a write repository".
* **Section 14 (COMPLETION CHECKLIST):**
  * Update the checkbox: `[ ] Write port -> Wp<Module>WriteRepository extends BaseWriteRepository; read port -> Wp<Module>ReadRepository extends BaseReadRepository; no pure read on the write port.`

### Target 2: `03-module-scaffolding.txt`
* **Section 7.1 TREE:**
  * Update the Infrastructure comments to reflect `Wp<Module>WriteRepository.php (extends BaseWriteRepository)` and `Wp<Module>ReadRepository.php (extends BaseReadRepository)`.
* **Section BASEREPOSITORY:**
  * Rename the section concept to reflect the three new base classes: `AbstractWpRepository`, `BaseWriteRepository`, and `BaseReadRepository`. Briefly describe that `BaseWriteRepository` holds `insert/write/deleteRecord/findRecordForUpdate` and `BaseReadRepository` holds `paginateRecords`.
* **Section TEMPLATES:**
  * Update `WpCouponWriteRepository` to `extends BaseWriteRepository`.
  * Update `WpCouponReadRepository` to `extends BaseReadRepository // only reads, no write methods inherited`.

### Target 3: `04-crud-pipeline.txt`
* **Step 2 CONTRACTS AND REPOSITORIES:**
  * WRITE: Update adapter to `Wp<Module>WriteRepository extends BaseWriteRepository`.
  * READ: Update adapter to `Wp<Module>ReadRepository extends BaseReadRepository`. Remove "extends NOTHING".
* **DEFINITION OF DONE:**
  * Update the persistence ports checklist item to match the new inheritance.

### Target 4: `06-ports-and-portability.txt`
* **Table T2 PORTS -> ADAPTERS:**
  * Update the WordPress (this repo) column for WRITE to `Wp<Module>WriteRepository extends BaseWriteRepository`.
  * Update the WordPress (this repo) column for READ to `Wp<Module>ReadRepository extends BaseReadRepository`.

## 3. Constraint Checklist for this Step
- [ ] Ensure the CQRS boundary remains strict: `BaseReadRepository` MUST NOT contain any write methods.
- [ ] Do not invent new methods during the documentation update; simply update the inheritance rules and class names.
- [ ] Play the completion bell once documentation updates are finalized.