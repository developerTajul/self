# STEP ID: core/02-repository-rules-cleanup
# GOAL: Clean up residual references to BaseRepository in the remaining rule files.

## CONTEXT
In the previous step, `BaseRepository` was successfully deleted and refactored into `AbstractWpRepository`, `BaseWriteRepository`, and `BaseReadRepository`. However, due to strict scope adherence, four rule files were left untouched and still contain stale references to `BaseRepository`.

## INSTRUCTIONS (CORE-PURE MODE)
Audit and safely update the following files. Replace mentions of `BaseRepository` with `BaseWriteRepository` (or the appropriate new abstract/read class contextually):
1. `02-framework-adapter-wp.txt` (approx. 5 hits)
2. `05-error-handling.txt` (approx. 2 hits)
3. `09-autonomous_execution.txt` (specifically C13)
4. `11-test-strategy.txt` (approx. 3 hits)

*Constraint:* Do not modify any `.php` files, actual code, or adapters during this step. This is purely a documentation and rule consistency pass.

## ACCEPTANCE
- A global grep/search for `BaseRepository` across the rule text files returns 0 hits (unless referring to historical context).
- The documentation accurately reflects the new CQRS inheritance tree.