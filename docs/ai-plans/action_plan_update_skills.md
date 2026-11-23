# STEP ID: core/03-skill-files-cleanup
# GOAL: Update outdated skill files to reflect the new WordPress `$wpdb` architecture and the recent Repository Split.

## CONTEXT
The project has migrated from a Laravel Eloquent architecture to a WordPress `$wpdb` architecture. Recently, `BaseRepository` was refactored and split into `AbstractWpRepository`, `BaseWriteRepository`, and `BaseReadRepository`. 
The AI assistant previously identified that two skill files (`module-scaffolder` and `update-action-builder`) still contain outdated references to `BaseRepository` and Eloquent methods (e.g., `lockForUpdate()`, `fill()`, `isDirty()`, `save()`). 

## INSTRUCTIONS (SKILL-UPDATER MODE)
You must use the `system:skill-updater` tool to modify the following skills. Do NOT edit these files manually via standard file writing tools; you MUST use the skill-updater tool.

### Task 1: Update `module-scaffolder` Skill
1. **Identify Outdated Rules:** Locate the references to `BaseRepository` (e.g., around lines 40 and 87) and any references to Laravel rule files (e.g., `02-framework-adapter-laravel.txt`).
2. **Apply Updates:**
   - Change instructions to scaffold write repositories extending `BaseWriteRepository`.
   - Change instructions to scaffold read repositories extending `BaseReadRepository`.
   - Update any rule file references from Laravel to the correct WordPress rule file names (e.g., `02-framework-adapter-wordpress.txt`).

### Task 2: Update `update-action-builder` Skill
1. **Identify Outdated Rules:** Locate the references to `BaseRepository` (e.g., around lines 70, 114, 139, 267) and Eloquent-specific methods like `lockForUpdate()`, `fill()`, `isDirty()`, `save()`, `refresh()`, or `pivot syncWithoutDetaching()`.
2. **Apply Updates:**
   - Replace `BaseRepository` with `BaseWriteRepository`.
   - Rewrite the data modification instructions to align with the WordPress `$wpdb` approach used in this project.
   - Specifically, replace Eloquent row locking (`lockForUpdate()`) with the new `$this->findRecordForUpdate(int $id, string $table)` method.
   - Replace Eloquent `fill()`/`save()` instructions with the custom `$this->write(int $id, array $values, string $table, string $action)` method.
   - Remove any instructions related to Eloquent relationship methods (like `pivot syncWithoutDetaching`).

## ACCEPTANCE
- Both the `module-scaffolder` and `update-action-builder` skills are successfully updated via the `system:skill-updater` tool.
- The updated skills instruct future agents to use the new CQRS hierarchy (`BaseWriteRepository` / `BaseReadRepository`).
- The updated skills instruct future agents to use `$wpdb`-based helper methods (`findRecordForUpdate`, `write`, `insert`) instead of Laravel Eloquent methods.