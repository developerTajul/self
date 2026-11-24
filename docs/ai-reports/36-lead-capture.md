# Report 36 — Step `Lead/3.1`: `LeadCommandService::captureLead`

**Step:** `Lead/3.1` | **Mode:** CORE-PURE | **Status:** `DONE_WITH_NOTES`
**Plan:** `docs/ai-plans/lead-module-action-plan.md` §5 Step 3.1 (Phase 3 opens here, lines 236–243)
**Predecessor:** report `35` (Step 2.5)

---

## 1. §0 PRE-FLIGHT

**CONTEXT HYDRATION.** Read the last three `DECISIONS.md` entries (`D-046`, `D-047`, `D-048`), the latest `PROJECT-LOG.md` row (`Lead/2.5`, `DONE_WITH_NOTES`), report `35`, and the Phase 3 plan text.

**OPEN decisions touching this step:** `D-048` was OPEN at entry but binds Step 3.4, not 3.1. `D-047` binds this step directly: the capture must **not** compute an outbox idempotency key, the adapter already derives `sha256('lead.captured:' . uuid)`.

**GIT TRACKING GUARD.** `git check-ignore -v .claude/rules .claude/skills docs/ai-reports` printed **nothing** for all three. Passed.

**DISK STATE.** `core/Lead/Application/Services/LeadCommandService.php` existed as a scaffold-time skeleton; the step's DTOs, response mapper and mapper contract existed as skeletons from `Lead/0.2` (`D-029`). Every path this step writes was therefore already occupied and named by the brief — no file was created at a free path except the ones §5 Step 3.1 names.

---

## 2. GOAL AND SCOPE

**GOAL.** One `executeInTransaction()` that decides create / enrich / merge from the contact keys, persists through the write port, and enqueues the transactional outbox event.

**ACCEPTANCE (plan lines 240–242).** `LeadCaptureDTO` + `LeadMapper` (plain array → DTO, blank → null) + `LeadResponseDTO` + `LeadResponseMapper`; create / enrich / merge decided by the lookups; two distinct leads locked ascending, `absorb()`, re-link mappings, delete the secondary **first**, then persist the survivor; after persistence the outbox event is enqueued **with the persisted id**; a duplicate idempotency key is a success; a duplicate-key race is retried once from the lookup; no business rule in the Service; no persistence exception imported.

---

## 3. FILES

| File | Action |
| --- | --- |
| `core/Lead/Application/DTOs/Requests/LeadCaptureDTO.php` | filled: contact fields, `source`, `meta`, `idempotencyKey`, `assignedTo` |
| `core/Lead/Application/DTOs/Requests/LeadFilterDTO.php` | filled: clamped list criteria |
| `core/Lead/Application/DTOs/Responses/LeadResponseDTO.php` | filled: presentation-safe lead shape |
| `core/Lead/Application/Contracts/LeadMapperContract.php` | filled: `mapToCaptureDTO()`, `mapToFilterDTO()` |
| `core/Lead/Application/Mappers/LeadMapper.php` | filled: array → DTO, `''`/whitespace → `null`, enum guards |
| `core/Lead/Application/Mappers/LeadResponseMapper.php` | filled: entity/projection → response DTO |
| `core/Lead/Application/Services/LeadCommandService.php` | implemented `captureLead()` and `enrichFromCrm()` (the latter consumed by Step 3.4) |
| `core/Lead/Application/Contracts/LeadInboundEnrichmentContract.php` | created (inbound seam, `D-051`) |
| `core/Lead/Application/DTOs/Requests/LeadCrmEnrichmentDTO.php` | created (inbound seam, `D-051`) |
| `core/Lead/Application/Contracts/LeadQueryContract.php` | additive `findByUuid()` |
| `core/Lead/Infrastructure/Repositories/WpLeadReadRepository.php` | implements `findByUuid()` |
| `app/Providers/LeadServiceProvider.php` | constructor arguments corrected; `LeadInboundEnrichmentContract` bound to the same service instance |

---

## 4. WHAT THE SERVICE DOES (verified against the code)

| Clause | Evidence |
| --- | --- |
| Contact keys built once at the boundary | `EmailAddress::fromNullable()`, `PhoneNumber::fromNullable()` before the transaction (`:76-77`) |
| Lookups by both keys, inside the transaction | `findByEmail()` / `findByPhone()` inside the closure (`:81-82`) |
| Two distinct leads → merge | `absorb()`, `relinkMappings()`, `delete()` secondary, then `update()` survivor (`:86-104`) |
| Exactly one match → enrich | `findByIdForUpdate()` then `enrichWith()` (`:105-117`) |
| No match → create | `LeadEntity::capture()` with a uuid from `UuidGeneratorContract` (`:118-130`) |
| Outbox enqueued with the persisted lead | `enqueueForCapturedLead($lead)` after the write (`:132`) |
| Duplicate key retried once | `catch (LeadDuplicateException)` → `captureAfterDuplicate()` → re-lookup (`:135-142`, `:153-188`) |
| No business rule in the Service | merge order, non-destructiveness and the contact-key invariant live in `LeadEntity`; the Service only sequences ports |
| No persistence exception imported | the only persistence exception caught is `LeadDuplicateException`, a Domain exception (`D-025`) |
| Time and uuid injected | `ClockContract->now()`, `UuidGeneratorContract->generate()` |

**S5 order.** The secondary is deleted before the survivor is written, and the mapping re-link runs first so no mapping is left pointing at a deleted row.

---

## 5. SELF-CHECK

| Check | Result | Evidence |
| --- | --- | --- |
| C1 files | PASS | every created path verified absent first; none duplicated |
| C2 scope | PASS | no file outside the step; provider edit is INHERENT WIRING |
| C3 syntax | PASS | `php -l` clean on every file in `core/Lead` |
| C4 contracts | PASS | `LeadCommandService implements LeadCommandServiceContract, LeadInboundEnrichmentContract` — verified by reflection |
| C5 wiring | PASS | each contract bound once in `LeadServiceProvider` (11 bindings, no duplicates) |
| C6 T8 greps | PASS | A: one pre-existing docblock match (`CacheInterface:46`, `D-034`); B: 0 |
| C7 transactions | PASS | all three write paths run inside `transactionManager->run()`; no manual begin/commit |
| C8 no vendor exception | PASS | `LeadDuplicateException` / `LeadNotFoundException` are module Domain types |
| C9 tests | PASS | paused per AGENTS §6 — none written, none run, none claimed |
| C10 file existence | PASS | verified before each write |

The shared harness run at the end of the phase (`report 42`) covers this file: autoload, interface compatibility, read/write port separation.

---

## 6. DEVIATIONS

1. **`enrichFromCrm()` was implemented in this step rather than Step 3.4.** The inbound use case lives in `LeadCommandService` (the module's only command entry point) and the port it satisfies had to exist before `CrmSync` could be wired. Step 3.4 consumes it; no CrmSync file names a Lead entity or repository (`D-051`).
2. **`findByUuid()` added to `LeadQueryContract`.** No existing read could resolve a lead by its public uuid, and the inbound payload carries only that. It is additive on the read port and implemented in `WpLeadReadRepository`.
3. **`LeadMapper` converts blanks to `null` and validates the idempotency key non-empty**, because the DTO's key is the caller's source-event key (`D-047` keeps the *outbox* key in the adapter).

---

## 7. STOP GATE

**Status:** `DONE_WITH_NOTES`. Next step: `Lead/3.2`.
