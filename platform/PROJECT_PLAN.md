# EMB EHR Platform: Project Plan

Single source of truth for building, hosting and running the platform. Written so that a person or an
AI assistant can read it cold and generate precise tickets from it (see section 0).

- Status: living document. Ticket IDs are stable; ticket status lives in Jira, not here.
- Last updated: 2026-11-05. Code state: all work committed on branch `dev`.
- Size of the plan: **48 tickets in total, of which only the 24 in M0 and M1 are created now.** The rest
  are outlines that become tickets when their milestone starts.
- **Deferred for now: billing, CPT data and the clearinghouse connection** (see section 8a).

---

## 0. How to use this document

### 0.1 Rules for generating tickets
1. One ticket per ID in section 7. Do not split, merge or invent tickets without asking.
2. **Title:** imperative, 10 words or fewer. **Description:** at most 3 sentences. **Acceptance
   criteria:** at most 4 bullets, each testable. Never paste design text into a ticket; end the
   description with `Plan ref: platform/PROJECT_PLAN.md, <ID>`.
3. Jira mapping: Epic = epic code (section 7 headings). Issue type = Task (infrastructure, scripts,
   docs) or Story (user-visible). Labels = milestone (`M1`) and size (`S`). Priority: P1 Highest,
   P2 High, P3 Medium. "Depends on" becomes an "is blocked by" link.
4. Story points: S = 2, M = 5, L = 8. **XL = split into child tickets when it is started.**
5. **Create tickets for M0 and M1 only.** Later milestones are outlines: do not create them yet.

### 0.2 Paste-ready prompt for a ticket-generating assistant
> Read `platform/PROJECT_PLAN.md`. Using the Jira MCP, create the epics in section 7, then the 24 tickets
> for milestones M0 and M1, following the rules in section 0.1. Keep each ticket short. Link
> dependencies. Print a table of the created keys when done. Do not create tickets for M2 or later.

### 0.3 Size scale (one developer working with an AI coding assistant)
| Size | Effort | Meaning |
|---|---|---|
| S | up to 1 day | Clear and small |
| M | 2 to 3 days | One focused piece of work |
| L | 4 to 7 days | Larger, may hide surprises |
| XL | over 1 week | Split when started |

### 0.4 Definition of done (every ticket)
Committed with a Conventional Commits message (see `AGENTS.md`); `php -l` and relevant tests pass;
verified in the local Docker stack (and on the pilot server for hosting work); related README or runbook
updated; no secrets in the repository.

---

## 1. Project context

**Client:** EMB, a medical billing company that gives its billing clients a free EHR, so hosting cost per
practice must stay low. **Builder:** Vebtual (one developer plus an AI coding assistant).
**Proposal:** "EMB EHR Platform: Architecture and Cost Proposal" (4 Nov 2026), approved by EMB.

**Product.** One shared platform runs many medical practices. Each practice has its own database, users,
settings, web address and enabled specialty modules. All practices share one codebase (OpenEMR, an
open-source EHR, used as the backend through its multisite feature). EMB staff manage all practices from
an admin portal. A new React interface gradually replaces OpenEMR's screens.

**Users**
- *Super admin* (the EMB billing team; in practice one person): signs in at `admin.<platform-domain>`,
  creates and pauses practices and opens any practice with full read and write access. (The
  cross-practice billing hub is deferred.)
- *Practice staff*: sign in at their practice's own address and see only that practice.
- *Practice admin*: a practice user who configures users, roles, branding, forms and fee schedules.

**Goals:** low cost per practice; strong separation between practices; one codebase to update; a new
practice in minutes; modern interface. Billing is a later goal (deferred).

**Non-goals for now:** billing, CPT data and the clearinghouse connection (deferred), patient portal,
mobile apps, e-prescribing, lab interfaces, migration of existing patient data.

**Constraints**
- One developer and a $20/month AI subscription with usage limits: keep tickets small and focused.
- Pilot (M1) target: 3 weeks. The full roadmap is roughly a year of solo work.
- **Fake data only until the go-live gate (SEC-01) is passed.**
- Budget baseline: about $117.51 per month for the AWS pilot (section 5).
- OpenEMR is GPL v3 (see PLN-01).

**Glossary**
| Term | Meaning |
|---|---|
| Practice / site | One medical practice. In OpenEMR a "site": folder `sites/<hostname>/` plus one database |
| Platform domain | Our root domain, written `<platform-domain>` |
| Golden database | A finished empty OpenEMR database copied to create a practice quickly |
| Worker | Background process that runs provisioning jobs; the only part holding the database root credential |
| Platform admin | A hidden OpenEMR user in every practice, used by the super admin |
| Shell | The React page that provides navigation and tabs and shows existing pages inside |
| Legacy page | An existing OpenEMR PHP screen |
| Layout-based form (LBF) | OpenEMR form defined by data (`layout_options`), used for specialty forms |

---

## 2. Locked decisions

| ID | Decision |
|---|---|
| D-01 | One shared platform, one database per practice on a shared database server |
| D-02 | OpenEMR multisite is the backend. New code lives in `platform/`, `frontend/`, `contrib/util/provisioning/` and one platform module. Core edits are minimal and documented in `platform/PATCHES.md` |
| D-03 | Each practice has its own hostname: default `<slug>.<platform-domain>`, optional custom domain by DNS. Hostnames starting `admin.` are reserved |
| D-04 | Super admin is one person with one login at `admin.<platform-domain>`, with full read and write on every practice |
| D-05 | One hidden `platform-admin` user per practice: random password changed on every use, protected from removal by practice admins (database triggers), hidden from the Users list (small core patch) |
| D-06 | One click opens a practice from a dropdown; no reason prompt; every open is audited automatically |
| D-07 | Pausing a practice locks its database account, which stops the practice completely |
| D-08 | Frontend: React shell first, hosting existing OpenEMR pages in same-origin iframes; then convert screens one at a time. Sign-in stays OpenEMR's, restyled |
| D-09 | Converted screens use the browser session (same origin) through small endpoints in our platform module that check permissions like the legacy page. OpenEMR's OAuth API is not used by our own screens |
| D-10 | Order: pilot platform (M1), clinical screens (M2), all practice settings (M3), specialty modules (M5), go-live readiness (M6). Billing (M4) is deferred |
| D-11 | Documents stay on a disk volume (OpenEMR has no S3 storage). S3 is for backups only |
| D-12 | One app server plus a managed database for the pilot: RDS MySQL 8.4 as proposed, MariaDB only as a fallback (confirmed by spike INF-02) |
| D-13 | Classic OpenEMR pages are blocked for practice users once their replacement exists. The super admin keeps access to them as a safety net (to be confirmed, PLN-02) |
| D-14 | Billing, CPT data and the clearinghouse are deferred and will be added later (see section 8a) |
| D-15 | Specialty modules are built once and enabled per practice: Internal Medicine, Urgent Care, Behavioral Health, ASC. No client-specific code |

---

## 3. Target architecture

```
                  Route 53 (DNS)           *.<platform-domain>   admin.<platform-domain>
                        |
                 EC2 app server  (Elastic IP, encrypted disk, no open SSH)
        +----------------------------------------------------------------------+
        |  Caddy      HTTPS certificates; routes by hostname                    |
        |  Apache+PHP OpenEMR (all practices) + our platform module             |
        |  Static     React shell (practice) and React admin app (later)        |
        |  Dashboard  PHP app on admin.<platform-domain> (platform/dashboard)    |
        |  Worker     runs provisioning jobs (only holder of DB root secret)    |
        +----------------------------------------------------------------------+
              |                                   |                 |
     RDS database server                    EBS disk           S3 (backups)
     one database + one user per practice   documents           dumps, snapshots
```

**Routing.** `admin.<platform-domain>` serves the dashboard only. `<slug>.<platform-domain>` serves the
React shell at `/app/`, and OpenEMR pages and sign-in at their normal paths. The repo-root `.htaccess`
implements this and blocks internals; in production Caddy or the load balancer repeats the rules.

**Tenancy.** OpenEMR picks the site from the hostname or `?site=`. Each practice database has its own
database user restricted to that database (verified). Nothing crosses between practices except what the
worker or platform admin does deliberately.

**Sign-in.** Practice users sign in on the restyled OpenEMR login page. The browser then holds the
OpenEMR session cookie; the shell, legacy pages and our platform-module endpoints all use it.

**Pause.** `ALTER USER <practice user> ACCOUNT LOCK` makes the whole practice fail at once; `UNLOCK`
restores it. Data is untouched.

**Security principles.** Web pages never run provisioning or hold the root credential. Passwords are
never stored (shown once, files deleted). Every super admin action is audited in the dashboard and shows
in the practice's own audit log under `platform-admin`. Two-factor sign-in and a network restriction
protect the admin address.

**Environments.** Local (Docker, `docker/development-easy`) and one pilot on AWS. Staging is added in M2.

---

## 4. Current state (what already exists)

Committed on branch `dev` and tested in the local Docker stack (MariaDB 12.3, PHP 8.5, x86).
**Not yet tested on RDS, ARM, MySQL, real HTTPS or a real domain.**

| Area | Where | What works |
|---|---|---|
| Provisioning | `contrib/util/provisioning/provision-practice.php`, `PracticeSpec.php` | `create` (full install or `--clone` from the golden database), `build-golden`, `status`, `list`, `reset-admin`, `destroy`; modules per practice; specialty template; seed SQL; first-login password prompt; all-or-nothing rollback |
| Fleet tools | `upgrade-practices.php`, `site-upgrade.php` | Per-practice schema upgrade with backup and dry run; refuses a database newer than the code |
| Templates | `site-template/`, `templates/general/` | Clean site files without credentials; example "Wellness Visit" form and lists |
| Admin portal | `platform/dashboard/` | Login (lockout, CSRF, session limits, forced password change), practices list and detail, create, reset admin, delete (typed confirmation), golden database panel, job queue and worker, once-only password reveal, audit log, three roles |
| Routing and protection | `.htaccess` (repo root) | Dashboard only on `admin.*`; provisioning records and dashboard internals blocked |
| OpenEMR patches | `index.php` (port-tolerant host match); `ZfcModule.php`, `InstallerController.php` (module listener hooks in CLI) | Small and documented |
| Local run | `docker/development-easy` | App `http://localhost:8300`; admin `http://admin.localhost:8300/login`; practices `http://<slug>.localhost:8300` |

**Test data to remove (ADM-03):** dashboard logins `owner@`, `ops@`, `view@company.test`; test practices
and the golden database in the local stack.

**Not built yet:** pause and resume; platform-admin account and guard; one-click open; two-factor
sign-in; per-practice DNS and HTTPS automation; ICD-10 data; theme; any React code; billing; modules;
hosting.

**Facts from research (OpenEMR 8.5.0)**
- Standard REST API: 95 routes (58 under `/api/patient`); FHIR: about 33 resource types. No routes for
  layout forms, billing, fee schedules, user creation, appointment categories or availability.
- Old pages mostly need `top.restoreSession()` (1,135 calls) plus `closeTab`, `twAddFrameTab`,
  `activateTabByName`, `refreshMainMenu`, `webroot_url`, a `left_nav` stub, `printLogSetup`.
  `interface/main/tabs/menu_json.php` returns the role-filtered menu (POST with CSRF token).
  `library/restoreSession.php` emits the JavaScript for `restoreSession`.
- Biggest legacy screens: patient summary 2,119 lines; calendar event editor 1,904 lines; field renderer
  `library/options.inc.php` 4,867 lines (about 156 field type cases).
- A new practice has 7 visit types, 1 facility, 1 possible provider and **no ICD-10 codes**. A few
  hundred service codes exist but no prices.
- Documents are stored on disk or CouchDB only. The session cookie is `SameSite=Strict`.
- The Users list that hides inactive users is built in `interface/usergroup/usergroup_admin.php` (~line 704).

---

## 5. Hosting and AWS purchasing

### 5.1 Baseline cost (from the approved proposal; unit prices not re-verified)
| Component | Monthly (USD) |
|---|---|
| EC2 t4g.large 2 vCPU 8 GB | 49.06 |
| Server disk 50 GB gp3 | 4.00 |
| Public IP | 3.65 |
| RDS MySQL db.t4g.medium | 47.45 |
| Database storage 50 GB | 5.75 |
| S3 100 GB | 2.30 |
| CloudWatch logs 10 GB | 5.30 |
| **Total** | **117.51** |

**Corrections:** documents need a bigger disk (S3 is not usable by OpenEMR); confirm ARM versus x86 and
the database engine (INF-02); add certificates (on the instance), DNS, email sending and server disk
snapshots. Check every price in the AWS Pricing Calculator before buying.

### 5.2 Buy by lead time, create compute by need
| When | Buy or create | Why |
|---|---|---|
| Day 1 | AWS account, root MFA, billing alarms, BAA accepted, domain, DNS zone | Almost free, and they have lead time or compliance value |
| Week 1 | Terraform base, then one small EC2 and one RDS instance | Only way to test ARM, the engine and HTTPS for real |
| Weeks 1 to 3 | Keep that pair as the pilot; stop it when idle | Cost accrues hourly; one environment only |
| After M2 | Staging; consider reserved capacity | Only once usage is predictable |
| Later | Multi-AZ database, second server, load balancer, WAF | When real patients or uptime promises require it |

Region: **eu-north-1 (Stockholm)**, chosen by the project owner on 5 Nov 2026. Every resource goes in
this one region (billing, DNS and CloudTrail console pages are global). Open point: confirm with EMB
that hosting US practices' data outside the US is acceptable (contract or client policy), and expect
about 100 to 150 ms extra latency for US users. Moving later means rebuilding, so settle it before real data.

Rules: everything in Terraform (destroy and recreate in minutes); tag every resource; budget alarms at
$50, $100, $150; no idle test stacks; no real patient data before SEC-01.

### 5.3 INF-02 spike results (local MySQL 8.4, run 5 Nov 2026)
Run in the dev OpenEMR container (8.5.0, x86 Linux under Docker Desktop) against a throwaway
`mysql:8.4` container (8.4.13, binary log on, `log_bin_trust_function_creators=OFF`). Cleaned up after.

| Check | Result |
|---|---|
| Create practice (installer, seed, login check) | Works, no changes needed |
| Build golden DB and clone a practice from it | Works; schema identical to the installed one (286 tables, 0 column differences) |
| Per-practice DB user limited to its own database | Works (reading another practice's table is denied) |
| Pause: `ALTER USER ... ACCOUNT LOCK` and unlock | Works (error 3118 "Account is locked") |
| Triggers guarding `platform-admin` (delete and deactivate blocked) | Works when created by root |
| Practice user dropping the trigger | Possible while it holds TRIGGER; denied after `REVOKE TRIGGER`. Normal app writes still work. So the practice user must NOT get ALL PRIVILEGES: grant everything except TRIGGER |
| Upgrade tool with backup (rewound one practice to 8.4.0) | Upgraded to 8.5.0 in 20 s; backup taken first |
| Dump (`mariadb-dump` 11.8 client against MySQL 8.4) | Works; includes triggers; restores cleanly (286 tables, trigger present) |
| Destroy practice | Works (database, user and site folder removed) |

Conclusion so far: no code change needed for MySQL 8.4. One change is needed in provisioning: create
practice DB users without TRIGGER (TEN-04). Timings here are slow because of Docker Desktop disk I/O
(golden build 215 s, clone 128 s, drop of a 286-table DB about 1 minute); expect much faster on EC2/RDS.

Image architectures: `openemr/openemr` and `mysql:8.4` are both published for amd64 and arm64.

### 5.4 INF-02 spike results on AWS (6 Nov 2026, eu-north-1)
Environment: EC2 `t4g.large` (ARM, Amazon Linux 2023, Docker 25), RDS for MySQL 8.4.11 `db.t4g.medium`
(single AZ, private, `log_bin_trust_function_creators=1`). Built by hand in the console (see decisions below).
The app ran as the `openemr/openemr:flex` image with this repo mounted, one container on the server,
provisioning run inside it as `apache` with `PROVISION_DB_ROOT_USER=dbadmin`.

| Check | Result |
|---|---|
| App image on ARM | Runs. Composer, npm build and installer all complete |
| First start of the dev-style image | About 20 minutes (npm install alone 9 min). Production image must bake this in (INF-04) |
| Create practice (full install) | Works, 5 min 47 s |
| Build golden database | Works, 341 s |
| Clone from golden | Works, **20 s** (about 17 times faster than a full install) |
| Schema A vs B | 286 tables each, 0 column differences |
| Practice DB user limited to its own DB | Works (reading another practice is denied) |
| Triggers (platform-admin guard) created by the RDS master user | Works (parameter group setting is what allows it) |
| Guard blocks delete and deactivate | Works; normal app writes unaffected |
| Practice user dropping the trigger | Possible while it holds TRIGGER; denied after `REVOKE TRIGGER` by `dbadmin` |
| Pause: `ACCOUNT LOCK` / unlock | Works (error 3118, then back to normal) |
| Upgrade tool with backup (8.4.0 to 8.5.0) | Works, 27 s, after the fixes below; no conflict with the guard triggers |
| Dump with `mariadb-dump` against RDS, then restore | Works; 286 tables and both triggers present |

**Decisions from the spike**
- **Database engine: RDS MySQL 8.4 confirmed.** MariaDB is not needed.
- **Server type: ARM `t4g.large` confirmed.** The x86 comparison was dropped because ARM works.
- **Infrastructure was created by hand in the console**, not with Terraform. The Terraform in
  `platform/terraform/` validates but was never applied; see its README. Repeatable Terraform is deferred
  to a later ticket (before staging, INF-09). A runbook of the console steps is still to be written.
- **Spend control is email alerts only** until the stop function is added (console or Terraform).

**Parameter and code changes needed (carry into INF-04, TEN-04, TEN-05)**
1. RDS parameter group: `log_bin_trust_function_creators=1`, `character_set_server=utf8mb4`.
2. RDS master user is `dbadmin`, not `root`: set `PROVISION_DB_ROOT_USER`.
3. Practice DB users get all privileges **except TRIGGER** (provisioning change, TEN-04).
4. The image makes `sites/` read-only. The worker needs `sites/` writable by `apache` (chown, mode 700),
   because it creates a folder per practice. Nothing else under it should become writable.
5. The image deletes `sql_upgrade.php`, `acl_upgrade.php` (and others) after setup. The upgrade wrapper
   must copy in `sql_upgrade.php` and `acl_upgrade.php` just before an upgrade and remove them right after
   (do not leave them in the web root).
6. `upgrade-practices.php` hides the upgrade's error output (only the exit code shows). Show it in the log (TEN-05).
7. Secrets: spike passwords were exported in the shell and stored in container settings. Not acceptable
   for production (INF-04).

**Not tested:** TLS to the database (`require_secure_transport` is OFF and the client did not verify the
certificate; decide in SEC-01), x86, multi-AZ, and RDS storage or CPU under load.

---

## 6. Milestones and order

| M | Name | Goal | Target |
|---|---|---|---|
| M0 | Foundations | External follow-ups, scope agreement | Days 1 to 2 |
| M1 | Pilot platform | Platform on AWS; admin portal complete; domain per practice; EMB-branded OpenEMR; React shell slice; demo on fake data | 3 weeks |
| M2 | Clinical screens | Seven clinical features rebuilt in React | about 6 to 10 weeks |
| M3 | Practice settings | Users and roles, branding, facilities, categories, lists, templates, fee schedules, form builder | about 6 to 10 weeks |
| M4 | Billing | **Deferred** until CPT data and the clearinghouse are included (section 8a) | not scheduled |
| M5 | Specialty modules | Internal Medicine, Urgent Care, Behavioral Health, ASC | about 4 to 6 weeks each |
| M6 | Go-live readiness | Compliance, penetration test, load test, disaster recovery | 2 to 3 weeks |

Durations from M2 onward are rough and will be re-estimated after M1.

### M1 plan (24 tickets for M0 and M1)
M1 is ambitious for one person in 3 weeks. P1 = must for the demo; P2 = stretch.
- **Week 1, hosting first:** PLN-01, PLN-02, INF-01, INF-02, INF-03, INF-04, INF-05, SEC-01.
- **Week 2, admin features:** TEN-03, TEN-01, TEN-02, TEN-04, ADM-01, INF-06, SHL-01.
- **Week 3, shell, theme, demo:** SHL-02, SHL-03, THM-01, ADM-03, OPS-01.
- **Stretch, in this order:** ADM-02, SHL-04, INF-07, INF-08.
- **Do not skip before any real patient data:** ADM-02 two-factor, INF-06 restore drill, SEC-01 gate.
- **If time slips, cut:** SHL-04, INF-08, INF-07, then THM-01. Never cut INF-01..06, TEN-01..04, SHL-01..03.

---

## 7. Work breakdown

Format: **ID Title** · Size · Priority · Milestone · depends on. Then description and `Done when`.

### PLN: Planning

- **PLN-01 Get legal advice on GPL and contracts** · S · P1 · M0 · depends: none
  Ask a lawyer about OpenEMR's GPL obligations when distributing, and about the contract chain between AWS, Vebtual, EMB and the practices (including the BAAs).
  Done when: written notes and an action list are saved in `platform/`.
- **PLN-02 Confirm pilot scope with EMB** · S · P1 · M0 · depends: none
  Write one page of what the 3-week pilot includes and excludes, agree staged delivery of the new interface, and confirm super-admin access to classic pages (D-13).
  Done when: EMB acknowledges the page in writing; D-13 confirmed or changed.

### INF: Infrastructure and hosting

- **INF-01 Set up AWS account, domain and DNS** · M · P1 · M1 · depends: none
  Create the AWS account with root MFA, an admin user, billing alarms ($50, $100, $150), CloudTrail and the accepted BAA. Register the platform domain and create its DNS zone.
  Done when: MFA and alarms on; BAA accepted; domain resolves through the DNS zone.
- **INF-02 Create Terraform base and run server spike** · L · P1 · M1 · depends: INF-01
  Minimal Terraform for network, security groups, remote state and tagging. First run our provisioning checks against a local MySQL 8.4 container, then test the app image on ARM and x86 and run provisioning against RDS for MySQL 8.4 (create, clone, pause lock, triggers, upgrade, dump). Use MariaDB only if MySQL fails.
  Done when: `apply` and `destroy` work from a clean checkout; server type chosen and MySQL 8.4 confirmed (or the reason for MariaDB written down), with results and parameter changes recorded here.
  **Revised scope (6 Nov 2026):** infrastructure built by hand in the console, so the Terraform apply/destroy criterion and the x86 test are dropped. The spike is complete and recorded in section 5.4. Open from this ticket: a console runbook, the budget stop function, and finishing Terraform later.
- **INF-03 Create app server and database** · M · P1 · M1 · depends: INF-02
  EC2 with Elastic IP, encrypted disk sized for documents, SSM access and no inbound SSH; encrypted RDS in a private subnet with 7-day backups and deletion protection.
  Done when: server reachable only through SSM with ports 80 and 443 open; database reachable only from the server.
  **Status (6 Nov 2026): done, created in the console** (EC2 `t4g.large`, RDS MySQL 8.4.11 `db.t4g.medium`). Deletion protection is still OFF on purpose and must be turned on before any real data.
- **INF-04 Deploy the app, dashboard and worker** · L · P1 · M1 · depends: INF-03
  Production image with OpenEMR and our code, a compose file (web, worker, Caddy), secrets in SSM Parameter Store or Secrets Manager, dashboard setup and worker as a managed service.
  Done when: a practice is created from the live dashboard; no secret in the repo or image.
- **INF-05 Set up HTTPS for admin and practice hostnames** · M · P1 · M1 · depends: INF-04
  Wildcard certificate by DNS challenge for `*.<platform-domain>` and `admin.`, with automatic renewal.
  Done when: `https://admin.<platform-domain>` and a practice subdomain load with valid certificates.
- **INF-06 Configure backups and run restore drill** · M · P1 · M1 · depends: INF-04
  Database snapshots, disk snapshot lifecycle, nightly per-practice dumps to encrypted S3 with expiry. Restore one practice from a dump and the database from a snapshot.
  Done when: both restores verified and timed; times written in the runbook.
- **INF-07 Add monitoring, alarms and server hardening** · M · P2 · M1 · depends: INF-03
  Logs to CloudWatch; alarms for CPU credits, disk, database storage, instance status, 5xx and certificate expiry; OS updates, IMDSv2 and a security group review.
  Done when: an induced fault sends an email; hardening checklist saved.
- **INF-08 Create CI/CD pipeline** · M · P2 · M1 · depends: INF-04
  GitHub Actions for lint, tests, frontend build, image build and deploy through SSM, with rollback.
  Done when: a merge to the main branch deploys to the pilot and can be rolled back.

### TEN: Tenancy and practice lifecycle

- **TEN-01 Add pause and resume** · M · P1 · M1 · depends: INF-02
  `pause` and `resume` commands that lock and unlock the practice database account, plus dashboard buttons with typed confirmation and a paused badge.
  Done when: a paused practice returns an error page and a resumed one works; both are audited.
- **TEN-02 Automate hostname and DNS per practice** · M · P1 · M1 · depends: INF-05
  Creating a practice adds its `<slug>.<platform-domain>` DNS record and checks the certificate is ready; reserved and invalid names are refused.
  Done when: a new practice opens on HTTPS within minutes with no manual step.
- **TEN-03 Create the platform module skeleton** · S · P1 · M1 · depends: none
  Custom OpenEMR module `oe-module-platform`, enabled in every practice by provisioning, holding our endpoints and the shell launcher.
  Done when: enabled on a new practice; a health endpoint returns the session user.
- **TEN-04 Add the platform-admin account with guard** · L · P1 · M1 · depends: TEN-03, INF-02
  Create hidden user `platform-admin` in every practice (and add it to existing ones): in Administrators, not a provider, random password never stored. Add database triggers that stop practice admins changing or removing it, remove the TRIGGER privilege from practice database users, and hide it from the Users list with a documented core patch.
  Done when: a practice admin cannot see, change or remove the account; the worker can still rotate its password; login counters still update.

### ADM: Admin portal

- **ADM-01 Add one-click Open practice** · L · P1 · M1 · depends: TEN-04, INF-05
  Dropdown of active practices; picking one rotates the platform-admin password and opens that practice in a new tab, signed in, then rotates it again. Test in real Chrome first (`SameSite=Strict` risk); if it fails, use a signed-token handoff in the platform module.
  Done when: opening works from `admin.` to a practice on another hostname; the open is audited; the used password no longer works.
- **ADM-02 Add two-factor sign-in and session hardening** · M · P2 · M1 · depends: INF-04
  TOTP with one-time recovery codes, shorter idle time, Secure cookie flag on, single active session. Required before any real patient data.
  Done when: sign-in needs the code; a recovery code works once; failures are audited.
- **ADM-03 Lock down admin access** · M · P1 · M1 · depends: INF-05
  Restrict the admin address to chosen IP addresses or a VPN, write and rehearse the lost-access recovery steps, and replace the test logins with the real owner.
  Done when: other addresses are refused; recovery rehearsed once; only the real account exists.

### SHL: React shell

- **SHL-01 Scaffold frontend and PHP launcher** · L · P1 · M1 · depends: TEN-03
  `frontend/` workspace (Vite, React, TypeScript, lint, tests, Node 24) building static files served at `/app/`, and a launcher page that checks the session, redirects to login when signed out, and gives the app its boot data (user, practice, web root, CSRF token, branding) and the `restoreSession` script.
  Done when: signing in lands on the shell; signed-out users reach the login page; CI builds the app.
- **SHL-02 Build shell layout, navigation and tabs** · L · P1 · M1 · depends: SHL-01
  Top bar, left navigation built from `menu_json.php` for the user's role, and a tab manager holding one iframe per tab.
  Done when: every menu entry opens its page in a tab; five tabs switch without losing state.
- **SHL-03 Add compatibility layer and session handling** · L · P1 · M1 · depends: SHL-02
  Provide the window functions old pages call (`restoreSession`, `closeTab`, `twAddFrameTab`, `activateTabByName`, `refreshMainMenu`, `webroot_url`, `left_nav`, `printLog*`, `dlgclose`), keep the session alive with a timeout warning, and show the current patient and encounter.
  Done when: patient finder, calendar and new patient pages work in tabs and save data; logout and timeout behave the same in every tab.
- **SHL-04 Click through main screens and add smoke tests** · M · P2 · M1 · depends: SHL-03
  Open the 30 main screens in the shell, fix what breaks, and add Playwright tests for sign-in, tabs, a form save and logout.
  Done when: a list of working and fixed screens saved; tests run in CI.

### THM: EMB look

- **THM-01 Create EMB theme, sign-in and branding** · L · P1 · M1 · depends: none
  Build a theme with OpenEMR's SASS theme system, restyle the login and OAuth pages, and apply each practice's logo, name and colours from its branding record.
  Done when: two practices show different branding; sign-in matches the brand on desktop and tablet.

### OPS and SEC: Operations and security

- **OPS-01 Write runbooks and guides** · M · P1 · M1 · depends: INF-06, ADM-01
  Developer setup, create and pause a practice, restore, upgrade (rebuild golden database, re-apply patches), rotate secrets, recover lost admin access, plus a super admin guide and an EMB handover page.
  Done when: each procedure has been followed once by someone using only the document.
- **SEC-01 Define go-live gate and threat model** · S · P1 · M1 · depends: none
  One page threat model and a checklist that must be fully ticked before real patient data: BAA signed, encryption verified, restore drill passed, audit logging verified, two-factor on, penetration test passed, risk assessment done.
  Done when: both saved in `platform/`; checklist referenced in the demo notes.

### CLN: Clinical screens (M2, outlines: create when M2 starts)

Rule for every converted screen: a menu entry opens the new screen instead of the old page; data comes
from platform-module endpoints that check permissions and are audited; a browser test covers it.

- **CLN-01 Foundations for converted screens** · XL · P1 · M2 · depends: TEN-03, SHL-03
  Endpoint conventions, data layer in the app, and the layout-driven form renderer.
- **CLN-02 Patient search and registration** · L · P1 · M2 · depends: CLN-01
  Search, duplicate check, registration form with insurance and employer sections.
- **CLN-03 Patient chart, encounters and documents** · L · P1 · M2 · depends: CLN-02
  Chart summary endpoint and screen, encounter list and creation, document upload and view.
- **CLN-04 Medications and allergies** · M · P1 · M2 · depends: CLN-01
- **CLN-05 Medical history, SOAP notes and diagnoses** · L · P1 · M2 · depends: CLN-01
  History endpoints (none exist today), SOAP with autosave, load ICD-10 codes into the golden database, code search, problem list.
- **CLN-06 Scheduling and calendar** · XL · P1 · M2 · depends: CLN-01
  Providers, visit types, availability and conflicts, day and week calendar, booking, check-in.
- **CLN-07 Audit check and end-to-end tests for clinical screens** · M · P1 · M2 · depends: CLN-02..06
  Confirm reads and writes appear in OpenEMR's audit log; tests in CI.

### TEN, INF and ADM follow-ups (M2, outlines)

- **TEN-05 Fleet page: versions, snapshots, upgrades** · L · P3 · M2 · depends: INF-04
  Worker records version, user and patient counts per practice; dashboard shows them and runs upgrade-all with backups.
- **TEN-06 Support custom domains** · L · P3 · M2 · depends: TEN-02
  Verify ownership by DNS, then allow a certificate; refuse unknown domains.
- **INF-09 Add staging environment** · M · P3 · M2 · depends: INF-04
- **ADM-04 Rebuild admin portal in React** · XL · P3 · M2 · depends: ADM-01
  JSON API for the dashboard and React screens using the shared design system.

### SET: Practice settings (M3, outlines)

- **SET-01 Users, roles and password flows** · L · P1 · M3 · depends: CLN-01
  List, create, edit, deactivate, assign access groups; first-login password change screen; never show or change the platform user.
- **SET-02 Practice profile, facilities, categories, schedules and lists** · L · P1 · M3 · depends: CLN-06
- **SET-03 Templates and fee schedules** · L · P1 · M3 · depends: CLN-01
  Letter, document and note templates; fee schedule editor with CSV import, using the codes already in the system (no licensed CPT data for now).
- **SET-04 Form builder for layout-based forms** · XL · P1 · M3 · depends: CLN-01
  Groups, fields, types, lists, conditions, preview. Prototype first.
- **SET-05 Settings permission review and practice audit viewer** · M · P2 · M3 · depends: SET-01

### MOD: Specialty modules (M5, outlines; refine before each)

- **MOD-01 Module framework for React** · L · P1 · M5 · depends: SET-04
  Per-practice enabled list; modules load on demand; backend enabling already works.
- **MOD-02 Internal Medicine module** · L · P2 · M5 · depends: MOD-01
- **MOD-03 Urgent Care module** · L · P2 · M5 · depends: MOD-01
- **MOD-04 Behavioral Health module** · L · P2 · M5 · depends: MOD-01
  Includes consent rules for substance-use records.
- **MOD-05 ASC module** · XL · P3 · M5 · depends: MOD-01

### Go-live readiness (M6, outlines)

- **SEC-02 Compliance pack** · L · P1 · M6 · depends: SEC-01
  HIPAA risk assessment and policies, subprocessor list and BAAs, incident response plan, practice offboarding and data export.
- **SEC-03 Penetration test and fixes** · M · P1 · M6 · depends: M3 complete
- **INF-10 Load test and disaster recovery plan** · M · P2 · M6 · depends: INF-06
  Simulate 10 practices; write recovery targets and rebuild steps; date a drill.

---

## 8. Assumptions and open questions

**Assumptions:** no data migration; 5 to 10 practices at launch; one super admin; the 3-week pilot
runs on fake data.

**Open questions:** custom domains at launch or later; confirmation of D-13; the chosen platform domain.

## 8a. Deferred (billing, CPT data and the clearinghouse)

Postponed by decision on 2026-11-05. Not scheduled, no tickets. Restore as milestone M4 when it is time.
Context already agreed, so it is not lost:
- **Clearinghouse:** EMB chose **TriZetto** (the proposal named TriZetto or Waystar). A clearinghouse
  sits between practices and insurers: it receives claim files, checks and routes them, and returns
  acknowledgements and payments. OpenEMR creates the claim files but cannot deliver them to payers itself.
- **CPT data:** the license is held by EMB. First confirm in writing that it covers delivery inside
  this software. A new practice has a few hundred service codes and no prices.
- **Earlier outline of the work (about 6 tickets):** charge capture around OpenEMR's fee-sheet code
  (`library/FeeSheet.class.php`, `src/Billing/BillingUtilities.php`); claim creation (837P) with checks;
  TriZetto connection with one EMB account shared by all practices and per-practice identifiers, by SFTP
  batch; acknowledgement and remittance posting (835) with a denials worklist; patient statements and
  receivables; a cross-practice billing hub (a service calling each practice in turn, or a reporting copy).
- **Lead time:** TriZetto needs a test account, SFTP details and technical guides, and each practice must
  be enrolled with them and with the payers. Ask early once billing is back in scope.
- **Cost:** clearinghouse fees are not in the hosting cost sheet.
- **Already usable in OpenEMR:** X12 claim creation, remittance posting, acknowledgement handling and an
  SFTP service exist in the code; there is no TriZetto-specific code.

## 9. Top risks
| Risk | Mitigation |
|---|---|
| All settings at launch (especially the form builder) is very large | Prototype SET-04 early; super admin keeps classic pages as a safety net (D-13) |
| Roadmap is about a year of solo work | Stage delivery with EMB (PLN-02); cut order in section 6 |
| RDS or ARM behave differently from local tests | Spike inside INF-02 in week 1 |
| Browser blocks the one-click sign-in handoff | Test in Chrome early (ADM-01); fallback to a signed-token handoff |
| OpenEMR upgrades break our patches or endpoints | `platform/PATCHES.md`, staging (INF-09), tests |
| Single server and single-AZ database | Acceptable for the pilot only; before real patients see SEC-01 and INF-10 |
| AI usage limits slow development | Small tickets, focused sessions, scripts over repeated exploration |
