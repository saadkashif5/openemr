# Super-admin dashboard

One login for the company team to create and manage every practice. It runs on the same server as
OpenEMR (plain PHP, served by the same Apache) and keeps its own data in a database called
`oe_platform` on the shared database server.

It manages practices; it never shows patient data. Practices are listed from the provisioning records
in `sites/.provisioning/`, and everything it does to a practice is done by
`contrib/util/provisioning/provision-practice.php`.

## How it fits together

```
operator --> dashboard page --> job recorded in oe_platform --> worker --> provision-practice.php
(browser)    (web, no root       (what, who, when)                (only part with the    (creates / resets /
              database access)                                      database root secret)  deletes practices)
```

- **Web pages** only record jobs and read files. They run with the restricted `u_platform` database
  user and never touch a practice database or run the provisioning script.
- **The worker** (`bin/worker.php`) picks jobs up one at a time and runs the script. It is the only
  part that needs the database root password and write access to `sites/`. In production run it as a
  separate task from the web servers, so a flaw in a web page cannot reach the root credential.
- **Initial passwords** are written by the script to a one-time file, never into the database. An
  operator reveals one once on the job page; the file is deleted at that moment (or after 24 hours).

## Set up (local Docker stack)

Run these inside the OpenEMR container, as the web server user:

```bash
cd /var/www/localhost/htdocs/openemr
php platform/dashboard/bin/setup.php                       # database, user, tables, var/config.php
php platform/dashboard/bin/create-operator.php you@company.com "Your Name" owner
php platform/dashboard/bin/worker.php                      # leave running (or add --once)
```

Then open `http://admin.localhost:8300/login` (browsers send any `*.localhost` name to your own
machine, so no hosts-file edit is needed; use `https://admin.localhost:9300` for the self-signed HTTPS
port). A new login must change its password on first use.

## Where it is served: admin.<your domain>

The dashboard answers on any host name starting with `admin.` and on no other host. The rules are in
the repo-level `.htaccess`:

- `admin.<domain>` serves the dashboard from its root (`/login`, `/practices`, ...) and nothing else of
  OpenEMR's pages.
- Every other host, including all practice domains, gets 403 for `/platform/dashboard/...`.
- Practice domains cannot start with `admin.`: the provisioning validation refuses them, so a
  customer can never take over that address.

For production:

1. Add a DNS record `admin.<your domain>` pointing at the same load balancer, and make sure the TLS
   certificate covers it (a wildcard certificate does).
2. **Add a load-balancer rule that sends host `admin.*` to the dashboard only.** The `.htaccess` rules
   are a second layer, not the only one: OpenEMR's own `apis/` and `oauth2/` folders carry their own
   `.htaccess`, which takes priority over the top-level one, so on the admin host those two endpoints
   would still answer (protected by OAuth, exactly as on any practice address). The load balancer rule
   removes that.
3. Set `'secure_cookies' => true` in `var/config.php`. Behind a load balancer that ends HTTPS, Apache
   sees plain HTTP, so it cannot tell on its own that the cookie should be marked Secure.
4. Restrict who can reach the host (VPN, IP allow-list, or a WAF rule), in addition to the login.

## Pages and roles

| Page | viewer | operator | owner |
|---|---|---|---|
| Practices list, practice details, job pages | yes | yes | yes |
| Create practice, reset admin password, reveal an initial password, audit log | | yes | yes |
| Open a practice (one click, signed in as its platform administrator) | | yes | yes |
| Delete a practice (type its domain to confirm), build the golden database | | | yes |

Failed sign-ins are counted: five in a row lock the account for 15 minutes. Sessions end after 30
minutes idle (12 hours at most). Every form carries a CSRF token. Every action, including each
password reveal, is written to the audit log, which never holds a password.

## Opening a practice (one click)

Pick an active practice in the dropdown on the Practices page (or press **Open practice** on its own page). The practice
opens in a new tab, signed in as its hidden `platform-admin` account (see `contrib/util/provisioning/README.md`).

```
click Open -> new tab: /jobs/<n>/open -> worker rotates the platform account's password (one-time file)
           -> the page collects it once (POST /jobs/<n>/handoff) and posts the practice's own login form in the same tab
           -> about 45 s later a second rotation makes the used password stop working
```

- **Nothing stores the password.** The worker writes it to a one-time file; the handoff claims it atomically (a second claim
  gets 410), deletes the file as it reads it, and the browser keeps it only for the moment it posts the form. The generic
  "Reveal password" is closed for these jobs, and a close job's file is deleted unread.
- **Safety net.** If nobody collects an opened password, a rotation still runs 120 seconds after the open finished. A newer
  open pushes a waiting rotation later, so an earlier open's rotation can never land in the middle of a later sign-in.
- **Audited:** `practice.open.requested`, `practice.open` (who, which practice, as which account) and the worker's `job.*`
  entries; the practice's own log also records the platform account signing in. A viewer cannot open (403).
- **Only the owner or an operator, and only for the requester's own job** (other operators get 404).
- **Security policy.** Every page forbids posting a form to another site (`form-action 'self'`). The waiting page alone may
  post to the one practice it is opening, and its script comes from `public/handoff.js` (no inline script).
- **Same site only.** A practice's session cookie is `SameSite=Strict`, so this works for a practice on the same site as the
  dashboard, that is `<slug>.<platform-domain>` (decision D-03). A practice on its **own domain** is refused (HTTP 422) until the
  signed-token sign-in exists (TEN-06). Tested in real Chromium: same site signs in, a different site is sent back to the login page.

**Configuration** (keys in `var/config.php`; defaults in brackets): `platform_domain` (none: open is refused until set, for
example `emb.example.com`), `practice_scheme` (`https`; `http` in a local stack) and `practice_port` (none).

**Upgrading an existing install:** run `php platform/dashboard/bin/setup.php` again. It adds the new job types and the
`run_after` column to `jobs`, and is safe to repeat. Restart the worker so it knows the new job types.

**Testing it:** `dev/open-practice-e2e.php` drives the dev stack's Chromium through the whole flow (see its header).

## Things to know

- **Practices created by hand** (before the provisioning script existed) have no record and do not
  appear. Only practices created through the script or this dashboard are listed.
- **No version or user counts per practice yet.** Showing them means reading each practice database,
  which only the worker may do. The plan is for the worker to record a periodic snapshot.
- **No second factor yet.** Sign-in is a password only. Add a TOTP step before real use.
- **Lockout can be used to lock someone out** by guessing their email wrongly five times. That is the
  usual trade-off; the lock lasts 15 minutes.
- **Keep internals off the web.** The repo-level `.htaccess` blocks everything under
  `platform/dashboard/` except `public/`, and `sites/.provisioning/`. It relies on `mod_rewrite`; in
  production also deny those paths in the Apache config.
- **In production** use HTTPS only, keep the worker separate from the web servers, and restrict who
  can reach the dashboard (see above).
