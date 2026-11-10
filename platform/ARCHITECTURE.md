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

**As built (AUD-01, 10 Nov 2026)**, in `interface/modules/custom_modules/oe-module-platform/`: `ApprovalService`
(propose, approve, reject, expire), a handler interface per action type (one built-in `platform.sample`), table
`platform_approval`, endpoint `public/approvals.php` (list, approve, reject; CSRF; session). Verified end to end: a
proposal by agent `scribe-agent` acting for a user waited; a signed-in admin approved it; it ran once as that admin;
a second approval, a missing CSRF token and a signed-out call were all refused; the decision is one atomic
`UPDATE`, so two simultaneous approvals cannot both run it.

**The agent door (built after review, 10 Nov 2026):** `POST /apis/<site>/api/platform_approval` with a bearer token, in
the module's `Bootstrap` (route and the scope `user/platform_approval.write`) and `Api/ApprovalApi`. The token names the agent
(client) and the user it acts for (`ActorResolver::fromRestRequest`). It only opens a pending approval (201); it never runs
anything. A proposal is refused unless the user the agent acts for could do the action themselves, so an agent cannot ask for
more than that user may. Verified: a token with the scope proposed an action, a token without it was refused (401), bad input
got 400, the approval recorded the agent's client id and the user, and the signed-in admin then approved it through the
session door, once. The log shows `actor=agent:<client> for:<user>` on the proposal and `actor=human:<user>` on the decision.
This is the "two entry points, one service" rule working: the same `ApprovalService` and permission check serve both doors.

Not built yet, on purpose: a screen for approvers (shell, M2) and real action handlers (they come with each feature).

## 5. Audit

| Layer | Where | Records |
|---|---|---|
| OpenEMR | `log` (with checksum) and `api_log` in each practice database | user, patient, event; API user, client, patient, request, response |
| Platform module (AUD-01, built) | same `log` table, event `platform` | each approval step with `corr=` (the approval's correlation id) and `actor=human:<user>` or `actor=agent:<client> for:<user>` |
| Platform | `oe_platform.audit_log` | super-admin actions: create, pause, open practice |

`api_log` can store full request and response bodies (copies of patient data). **Default for new practices is now
minimal logging** (`api_log_option = 1`: who, what, when, patient; no bodies), and `bin/purge-api-log.php` deletes rows
older than the practice's retention setting (default 90 days). Whether to keep bodies, and for how long, is still a
decision (plan section 8b, question 5). Logs stay inside the practice database, so a practice's audit trail
is deleted or exported with that practice.

Limits to know: the correlation id is created for our module endpoints and for approvals; it is not on OpenEMR's
legacy pages, and `api_log` has no column for it, so token API calls are linked to approvals through the user, client
and time rather than the id. An approval that expires is recorded in `platform_approval` (status and time) but not as a
`log` entry. OpenEMR keeps the tamper-evidence checksum in `log_comment_encrypt` (our entries have one), not in `log.checksum`.

## 6. Interoperability (Phase 1)

- OpenEMR provides FHIR R4, US Core 8.0 and SMART on FHIR v2.2.0. Per practice the endpoints are
  `https://<practice-domain>/apis/<site>/fhir` and `/oauth2/<site>/...`. The site id is the practice's site
  folder name, which in our provisioning is its domain.
- Provisioning sets, by default, the API globals (`rest_api`, `rest_fhir_api`) and the practice's `site_addr_oath`
  (FHI-01, done); `apply-api-defaults <domain>` does the same for an existing practice.
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

Status (10 Nov 2026): checks 1, 2 and 4 are verified (FHI-01, AUD-01 and the agent door). Check 5 holds for every
endpoint we have written (`health.php`, `approvals.php`, the token route take no practice parameter; the practice comes
from the hostname or the token's own site). Check 6 holds by default: provisioning sets `rest_system_scopes_api = 0`, so
`system/*` scopes are not offered. Check 3 (a screen's endpoint and the equivalent API route agree) starts with the first
converted screen (CLN-01); there is no pair yet to compare. Still to add: an automated test for 5 and 6 that runs against a fresh practice.
