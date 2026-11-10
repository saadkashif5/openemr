# oe-module-platform

EMB platform module. Provisioning installs it, runs its SQL and enables it in every new practice, whether or not the
practice's spec lists it (`provision-practice.php apply-platform-module <domain>` does the same for an existing one).
Design: `platform/ARCHITECTURE.md`.

## Endpoints (session, same origin)

| Endpoint | Method | Result |
|---|---|---|
| `public/health.php` | GET, HEAD | `200` with the signed-in user, `401` when signed out, `405` otherwise. Never cached |
| `public/approvals.php` | GET | pending approvals the signed-in person may decide, plus a CSRF token |
| `public/approvals.php` | POST `action=approve\|reject`, `id`, `note`, `csrf_token_form` | decide; an approved action runs once, as that person. `401` signed out, `403` no permission / agent / bad CSRF, `404` unknown, `409` already decided or expired |

Every response carries `X-Correlation-Id` (the caller's, if it is a valid UUID, otherwise a new one).

## Token API (the agent door)

| Endpoint | Method | Result |
|---|---|---|
| `/apis/<site>/api/platform_approval` | POST, bearer token with scope `user/platform_approval.write` | body `{"action_type","resource","patient_id","payload"}`; `201` pending with `approval_id` and `correlation_id`. Nothing runs. `400` bad input or unknown action, `401` no token or no scope, `403` the user the agent acts for could not do the action |

Registered in `src/Bootstrap.php` (route and scope) and handled by `src/Api/ApprovalApi.php`. A person decides through
`public/approvals.php`; both doors use the same `ApprovalService`.

## Human approval (AUD-01)

An agent (or any caller) **proposes** an action; a person with the required permission **approves or rejects** it; an
approved action **runs once, as the approver**. Code: `src/Approval/`.

- Add a feature's sensitive action by writing a class that implements `ApprovalActionHandler` (`actionType()`,
  `requiredAcl()`, `execute()`) and registering it in `ApprovalServiceFactory`. `platform.sample` is the built-in
  example and does nothing but echo its payload.
- The handler gets exactly the payload that was proposed. The approver's permission is checked against OpenEMR's ACL.
- Only a person can decide. Decisions are atomic (one `UPDATE`), so an approval cannot run twice, and not after it expired
  (default 24 hours, setting `approval_ttl_minutes`).
- Table `platform_approval` holds the request and what happened; `platform_setting` holds the settings.

## Audit

Each step (`approval.proposed`, `approved`, `rejected`, `executed`, `failed`, `denied`) is written to the practice's own
OpenEMR log (`log` table, event `platform`) with `corr=<approval's correlation id>` and `actor=human:<user>` or
`actor=agent:<client> for:<user>`. OpenEMR stores log comments base64-encoded, with its checksum in `log_comment_encrypt`.

## API log retention

`api_log` stores API requests (new practices log minimally, without bodies). `bin/purge-api-log.php <site>` deletes rows
older than the practice's `api_log_retention_days` setting (default 90). Schedule it per practice (cron or the worker).

## Tests

`tests/Tests/Isolated/Modules/Platform/` (approval rules, correlation ids, health).
