# STEP ID: core/06-remaining-skills-cleanup

# GOAL: Purge all remaining Laravel/Eloquent references (e.g., `Route::resource()`, `DB::`, Eloquent models) from the remaining 5 skill files and align them fully with the WordPress `$wpdb` and CQRS architecture.

## CONTEXT

In previous steps, we upgraded core rule files, established the `AbstractWpRepository`, `BaseWriteRepository`, and `BaseReadRepository` hierarchy, and cleaned up two key skills. However, our autonomous audit reports (`D-2026-11-15-08` and `D-2026-11-15-10`) confirmed that 5 remaining skill files still contain stale Laravel routing (`Route::resource()`), non-existent rule filenames (`02-framework-adapter-laravel.txt`), and obsolete Eloquent/DB facade patterns.

## INSTRUCTIONS (MANUAL EDIT MODE)

Per AGENTS.md §0 Startup Protocol, hydrate context before proceeding. Then, directly edit the `SKILL.md` file inside each of the following directories located in `.claude/skills/`:

1. `delete-action-builder`
2. `edit-form-builder`
3. `form-builder`
4. `index-action`
5. `store-action-builder`

For each file, apply these mandatory corrections:

* **Routing:** Remove all references to Laravel's `Route::resource()`. Replace them with instructions to use WordPress REST API routing (`register_rest_route()`) or plugin routing adapters.

* **Rule File References:** Update any mention of `02-framework-adapter-laravel.txt` to `02-framework-adapter-wordpress.txt`. Fix rule filename separators (e.g., ensure `09-autonomous_execution.txt` uses underscores).

* **Database & Repositories:** Ensure database operations explicitly adhere to our CQRS boundary (`BaseWriteRepository` / `BaseReadRepository`). Strip out Eloquent methods (`save()`, `delete()`, `fill()`, `lockForUpdate()`, Models, `DB::` facade calls) and replace them with our custom `$wpdb` helpers (`findRecordForUpdate`, `write`, `insert`).

## ACCEPTANCE

* A grep across `.claude/skills/` for `Route::resource` returns 0 hits.

* A grep across `.claude/skills/` for `02-framework-adapter-laravel.txt` returns 0 hits.

* All skill files strictly enforce the WordPress and CQRS repository patterns without any Laravel/Eloquent leftovers.

* The changes are saved on disk and ready for git tracking under our unignored `.claude/` directory.