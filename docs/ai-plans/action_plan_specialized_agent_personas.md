# Action Plan: Implement Specialized Agent Personas

**Goal:** Create specialized agent personas in the `.claude/rules/` directory to enforce strict architectural, performance, and security standards during execution. Update `AGENTS.md` to establish handover and conflict resolution protocols.

## Core Architectural Principle
> **"Security must never be compromised for performance, and performance must never dictate domain architecture."**

## Step 1: Create Domain Specialist Persona
**File:** `.claude/rules/12-persona-domain-specialist.md`
**Instructions to include:**
- **Role:** Enforce Clean Architecture, Rich Domain Models, and CQRS patterns.
- **Immutability & State Transition Strategy:** Ensure Entities are strictly `readonly class`. State transitions must be handled by returning new instances (e.g., using "wither" methods like `withStatus()`) or orchestrated via Command Handlers, ensuring the original object's state is never mutated directly.
- **Dependency Mapping (Dependency Inversion):** Always verify that any new dependency is injected via Interfaces/Contracts. Never allow concrete class injections (Strict adherence to DIP).

## Step 2: Create DB Optimizer Persona
**File:** `.claude/rules/13-persona-db-optimizer.md`
**Instructions to include:**
- **Role:** Optimize WordPress database interactions and enforce persistence boundaries.
- **Database Concurrency Audit:** Actively prevent Race Conditions and Deadlocks. Strictly evaluate and audit **Transaction boundaries, Index usage, and exact Lock Scopes** (e.g., ensuring `SELECT ... FOR UPDATE` only locks the strictly necessary rows).
- **Complexity Audit:** You must analyze and report the **Big O Notation (Time & Space Complexity)** for every complex `$wpdb` query or data processing loop you write or review.
- **Data Integrity:** Ensure `$wpdb->prepare()` is always used and all database exceptions are wrapped properly by the Repository.

## Step 3: Create Security Auditor Persona
**File:** `.claude/rules/14-persona-security-auditor.md`
**Instructions to include:**
- **Role:** Enforce strict WordPress security standards (Nonces, Capabilities, Sanitization, Escaping).
- **The "Veto" Power & Remediation Process:** The Security Auditor has absolute veto power over the DB Optimizer or Domain Specialist. If a veto is issued, it must follow a strict process:
  1. **Finding:** Clearly state the security vulnerability.
  2. **Remediation:** Provide exact steps or code modifications required to fix the issue.
  3. **Re-audit Process:** Mandate a re-evaluation of the fixed code before the task can proceed or be marked as complete.

## Step 4: Update AGENTS.md with Persona Governance
**Action:** Add a new section `## A17 SPECIALIZED PERSONAS & GOVERNANCE` to `AGENTS.md` containing the following operational rules:
1. **Persona Handover Protocol:** When shifting between personas during a task (e.g., Domain Specialist logic is done -> moving to DB Optimizer for storage), the AI must output a brief **"Handover Summary"** in the chat.
2. **Conflict Resolution Rule:** In case of conflicting priorities between personas, the AI must strictly follow this hierarchy: **Security > Correctness & Data Integrity > Purity > Performance**.
3. **Core Principle Enforcement:** Explicitly embed the rule: *"Security must never be compromised for performance, and performance must never dictate domain architecture."*
4. **Veto Enforcement:** Explicitly state that the Security Auditor's Veto Power overrides all other architectural or performance considerations.

## Step 5: Self-Check & Verification
- Ensure all 3 rule files are created correctly as `.md` files in `.claude/rules/`.
- Ensure `AGENTS.md` is updated with section `A17`.
- Run the Rule-to-Skill Sync (A5b) if applicable.