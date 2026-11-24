# Report 41 — Step `Lead/3.6`: query services

**Step:** `Lead/3.6` | **Mode:** CORE-PURE | **Status:** `DONE_WITH_NOTES`
**Plan:** `docs/ai-plans/lead-module-action-plan.md` §5 Step 3.6
**Predecessor:** report `40` (Step 3.5)

---

## 1. §0 PRE-FLIGHT

Read `D-046`, `D-047`, `D-048`; latest log row; plan Step 3.6. Git tracking guard: no output. No OPEN decision binds this step.

---

## 2. GOAL AND SCOPE

**GOAL.** Read use cases for the leads list, the sync audit log and the dead-letter inspector — read ports only, criteria clamped through a module `<Module>Rules` class, projections rather than entities in lists.

**ACCEPTANCE.** Query services depend on read ports only, never write ports; filter DTOs clamped; `Result` carrying response DTOs; no transaction, no event, no write.

---

## 3. FILES

| File | Action |
| --- | --- |
| `core/Lead/Application/Services/LeadQueryService.php` | `getLead()`, `getLeads()` |
| `core/CrmSync/Application/Services/SyncQueryService.php` | `getSyncEvents()`, `getDeadLetters()` |
| `core/CrmSync/Application/DTOs/Requests/SyncEventFilterDTO.php` | created |
| `core/CrmSync/Application/DTOs/Requests/DeadLetterFilterDTO.php` | created |
| `core/CrmSync/Domain/Rules/SyncReadRules.php` | created: `DEFAULT/MIN/MAX_PER_PAGE`, `clampPerPage()` |
| `core/CrmSync/Application/Mappers/SyncMapper.php` | `mapToSyncEventFilterDTO()`, `mapToDeadLetterFilterDTO()` |
| `core/CrmSync/Application/Mappers/SyncResponseMapper.php` | projection → response DTOs |
| `core/CrmSync/Application/DTOs/Responses/SyncEventResponseDTO.php` | created |
| `core/CrmSync/Application/DTOs/Responses/DeadLetterResponseDTO.php` | created |

`LeadQueryService` was already in scope from Step 3.1's DTO work; this step is where its list path is complete.

---

## 4. WHAT THE SERVICES DO

| Clause | Evidence |
| --- | --- |
| Read ports only | `LeadQueryService` → `LeadQueryContract`; `SyncQueryService` → `SyncEventQueryContract`, `DeadLetterQueryContract` |
| No write port, no transaction | neither constructor takes a write repository or a transaction manager |
| Clamped criteria | `LeadMapper::mapToFilterDTO()` → `LeadRules::clampPerPage()`; `SyncMapper` → `SyncReadRules::clampPerPage()` |
| Domain filter object validates bounds | `LeadFilter` and `SyncEventFilter` reject an out-of-range page size themselves |
| Lists carry projections, not entities | `LeadProjectionDTO`, `SyncEventProjectionDTO`, `DeadLetterProjectionDTO` → response DTOs |
| One record may carry an entity | `getLead()` returns a `LeadResponseDTO` built from the entity; no list does |
| Failures are ordinary results | not found → `Result::notFound()`; unexpected → logged + generic failure |
| Pagination is preserved | `PaginatedResult::map(...)` maps the page and keeps the paging metadata |

**Why the audit list has no payload.** `SyncEventProjectionDTO` omits `payload_json` deliberately: the audit screen shows what happened, and a page of `longText` payloads would move far more bytes than the table renders (the same reasoning the dead-letter inspector already uses).

**Unused dependency removed.** `SyncQueryService` had been drafted with `CrmMappingQueryContract` in its constructor and never used it; the parameter and the provider argument were removed (`D-055`) rather than leaving a service that appears to read mappings.

---

## 5. SELF-CHECK

| Check | Result | Evidence |
| --- | --- | --- |
| C1 files | PASS | each new path verified absent before creation |
| C2 scope | PASS | no delivery, no write path, no migration |
| C3 syntax | PASS | `php -l` clean |
| C4 contract | PASS | both services implement their inbound query contracts (reflection) |
| C5 read/write split | PASS | harness: no read port declares a write method; no write port declares a listing read |
| C6 clamping | PASS | out-of-range `perPage` clamps; `page < 1` becomes 1 |
| C7 provider parity | PASS | `SyncQueryService` constructor matches the provider's six arguments |
| C8 T8 | PASS | A: 1 pre-existing docblock match; B: 0 |

---

## 6. DEVIATIONS

1. **`InvalidLeadFilterException` / `InvalidSyncFilterException` catch blocks are defensive only.** The request mappers convert raw input into bounded DTOs before the service builds a filter, so the filter constructor's own guard normally never fires; the catch remains so a filter built by any future caller still produces a clean failure instead of a generic one.
2. **`SyncReadRules` duplicates the numeric bounds that `SyncEventFilter` also enforces.** The rules class clamps request input *before* the filter is built (R12.2), and the filter rejects any bounds it is handed directly. The two are asserted to the same numbers by the harness's clamping check, not by sharing a constant — `SyncEventFilter` keeps private constants because it is a frozen-shape Domain value object.

---

## 7. STOP GATE

**Status:** `DONE_WITH_NOTES`. Phase 3 implementation complete; see report `42`.
