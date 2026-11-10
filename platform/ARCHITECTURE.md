# Architecture: AI-ready foundation

Status: **draft for review by the lead** (ticket ARC-01). D-16 was confirmed by the project owner on
10 Nov 2026. Charge capture stays deferred (D-14). The AI provider and data region are the project owner's
decisions and are still open (`PROJECT_PLAN.md` section 8b).

The client wants the EHR to be AI-native from day one without slowing the MVP. This page fixes the few
structural rules that make later AI work possible. It does not design any AI feature.

## 1. Principles

1. **One practice, one trust boundary.** Each practice has its own hostname, database and keys. A credential
   issued by one practice means nothing to another. Nothing in this design weakens that.
2. **One service layer, two entry points** (D-16). Business logic and permission checks live in services.
   Our own screens reach them through session endpoints; agents, MCP and third-party apps reach them
   through OpenEMR's OAuth, REST and FHIR API. Neither entry point holds logic of its own.
3. **Agents are users, not back doors.** An agent has its own identity, acts for a named human, and is
   limited to that human's permissions or less.
4. **Sensitive actions wait for a human.** An agent proposes; a person approves; the action runs once.
5. **Everything is attributable.** Every request records who (human or agent, and on whose behalf), which
   practice, which patient, and a correlation ID that ties a proposal, an approval and the result together.
6. **No standing cross-practice access for software.** Only the super admin (a person) crosses practices,
   through the existing audited one-click open (ADM-01). No agent, token or MCP server does.

## 2. The two entry points

```
 React screens ──(browser session)──► platform-module endpoints ─┐
                                                                 ├─► services + permission check ─► practice DB
 agents / MCP / SMART apps ─(OAuth token)─► OpenEMR REST + FHIR ─┘      (role + practice, one place)
```

- OpenEMR already works this way: its REST controllers in `src/RestControllers/` call services in
  `src/Services/`. Our module endpoints must call the **same services** and the same ACL checks
  (`AclMain`), never reimplement them.
- A new capability is built as a service first. The session endpoint and, when needed, an API route are
  thin wrappers. Reviewers reject logic in an endpoint.
- Where OpenEMR has no API (for example medical-history reads), we add the service and the session
  endpoint now; the token route is added when an agent needs it, without changing the service.

## 3. Agent identity

- Each agent is an **OAuth client** in the practice (`oauth_clients`), registered by that practice's admin.
- User-delegated work uses the authorization-code flow with PKCE: the token is issued for a user and an
  agent client. `api_log` already records both (`user_id`, `client_id`), so "agent X acting for Dr. Y" is
  captured today.
- Backend tokens (`system/*` scopes, client credentials) are **off by default** and need an explicit decision
  per use, because they are not tied to one patient or one user.
- Scopes follow SMART v2 granular scopes. Default for any agent: read-only on the minimum resources.
- Credentials belong to the practice; the super admin never receives a standing agent token (open
  question 4 in section 8b of the plan).

## 4. Human approval

Added in the platform module (ticket AUD-01), per practice database:

| Field | Meaning |
|---|---|
| correlation_id | ties the proposal, decision and execution together |
| proposed_by_client, on_behalf_of_user | the agent and the human it acts for |
| action_type, resource, payload | what would be done (stored exactly as it would be executed) |
| status | pending, approved, rejected, expired, executed, failed |
| decided_by, decided_at, executed_at, result | who decided, when, and what happened |

Rules: the payload shown to the approver is the payload that executes; an approval runs once; it executes
with the **approver's** permissions, not the agent's; pending items expire; the approver cannot be the agent.
Sensitive actions (to be listed per feature): signing notes, prescribing, placing orders, messaging patients,
deleting data, submitting claims, bulk export.

## 5. Audit

| Layer | Where | Records |
|---|---|---|
| OpenEMR | `log` (with checksum) and `api_log` in each practice database | user, patient, event; API user, client, patient, request, response |
| Platform module (AUD-01) | same databases | adds actor type (human or agent), on-behalf-of, correlation ID, approval steps |
| Platform | `oe_platform.audit_log` | super-admin actions: create, pause, open practice |

`api_log` stores full request and response bodies (copies of patient data). Retention and what to keep is a
decision (plan section 8b, question 5). Logs stay inside the practice database, so a practice's audit trail
is deleted or exported with that practice.

## 6. Interoperability (Phase 1)

- OpenEMR provides FHIR R4, US Core 8.0 and SMART on FHIR v2.2.0. Per practice the endpoints are
  `https://<practice-domain>/apis/<site>/fhir` and `/oauth2/<site>/...`. The site id is the practice's site
  folder name, which in our provisioning is its domain.
- Provisioning must set, by default, the API globals (`rest_api`, `rest_fhir_api`) and the practice's
  `site_addr_oath`. The mechanism exists (`globals` in the spec) but nothing sets them yet (FHI-01).
- Each practice must have its own OAuth signing keys, created in its own
  `sites/<site>/documents/certificates/oaprivate.key`, never copied from another site, the golden database or
  the image. **Verified locally (10 Nov 2026, FHI-01):** practices created from our clean site template get
  their own key on first OAuth use. Practices created before the template (practice-a, -b, -d) started with a
  copy of `default`'s key, but OpenEMR replaced it by itself on their first OAuth use, because their database
  had no key record. Even while a key was shared, a token from one practice was refused by the others (each
  practice also checks tokens against its own database). `provision-practice.php rotate-oauth-keys <domain>`
  forces a new pair (for a suspected leak); tokens issued before it stop working.
- A client registered through the open registration endpoint starts **disabled** and cannot give itself the
  password grant (OpenEMR's `ClientGrantTypePolicy`); an admin approves it. Agents inherit this: they cannot
  self-approve or self-escalate.
- HTTPS with a valid certificate on every practice hostname is required for OAuth (INF-05).

## 7. Real-time and AI scribing (later)

Nothing in Phase 1 blocks it, if these hold: HTTPS everywhere (INF-05); the firewall opens UDP and a TURN
server only when WebRTC starts; audio runs in a **separate service**, not in the OpenEMR containers; audio and
transcripts are PHI and follow section 5; and the AI provider has a signed BAA before any patient data reaches
it (plan section 8b, questions 2 and 3).

## 8. Agent-friendly interface (long term)

Screens are built so that every action a person can take is a named call to an endpoint (section 2). That is
also what lets WebMCP or AG-UI describe the interface to an agent later. No business logic in React components.

## 9. How we check this holds (acceptance tests)

1. A token issued by practice A is refused by practice B (FHI-01).
2. Two practices have different OAuth signing keys (FHI-01).
3. A new screen's endpoint and the equivalent API route return the same data and refuse the same users
   (tested per feature from CLN-01 on).
4. An agent action marked sensitive does not run until approved, runs once, and is attributable end to end
   (AUD-01).
5. No endpoint accepts a practice identifier as a parameter; the practice always comes from the hostname.
6. A standing `system/*` token does not exist in any practice by default.
