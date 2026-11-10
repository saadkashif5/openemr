# Practice provisioning

`provision-practice.php` creates, inspects and destroys OpenEMR practices. A practice is a multisite
"site": a `sites/<domain>/` directory plus its own database and database user.

## Run

Run it inside the OpenEMR container, as the web server user (the installer refuses to run as root):

```bash
export OPENEMR_ENABLE_PROVISIONING=1
php provision-practice.php create  --spec=practice.json [--verify-url=http://localhost]
php provision-practice.php status  <domain>
php provision-practice.php list
php provision-practice.php destroy <domain> --confirm=<domain>
```

Logs go to stderr. The result is one JSON document on stdout. For `create` it contains the admin's
initial password, which is shown once and never stored.

| Environment variable | Default | Purpose |
|---|---|---|
| `PROVISION_DB_ROOT_PASS` | `MYSQL_ROOT_PASS` | Database root password (never put it in a spec) |
| `PROVISION_DB_HOST` / `_PORT` / `_ROOT_USER` | `MYSQL_HOST` / 3306 / root | Database server |
| `PROVISION_DB_LOGIN_HOST` | `%` | Host part of the new database user. Must allow the app container |
| `PROVISION_TEMPLATE_SITE` | `../contrib/util/provisioning/site-template` | Directory copied for each new practice, relative to `sites/`. Must not contain `sqlconf.php` |
| `PROVISION_TEMPLATES_DIR` | `./templates` | Specialty templates |
| `PROVISION_STATE_DIR` | `sites/.provisioning` | Per-practice state files and `audit.log` (no secrets) |

## Spec

See `example-spec.json`. `domain` becomes the site id (lowercase letters, digits, `.`, `-`).
`globals` are OpenEMR global settings written at install time. `modules` must be empty for now.

## What `create` does

1. Validate the spec and refuse if the site directory, database or user already exists unrecorded.
2. Generate random database and admin passwords.
3. Run the stock `Installer`: create database and user, load schema, copy the template site directory,
   write `sqlconf.php`, create the admin and the access-control roles, apply `globals`.
4. Apply the specialty template, if `templates/<specialty>/` exists (each part is optional):
   - `seed.sql`: run in the new practice's database, as its own database user. Use it for custom
     encounter forms, dropdown lists and other starting data. A failing statement rolls back the
     whole practice.
   - `globals.json`: OpenEMR settings, applied at install time.
   - `site/`: files copied over the new site directory, e.g. logos or `LBF/` plugin files.

   `templates/general/` is the first real one (see its README).
5. Set the admin email.
6. Verify in the database (admin exists, has a password, is in an access-control group) and, with
   `--verify-url`, fetch the login page and sign in as the new admin.

If any step fails, the database, user and site directory are removed and the practice is recorded as
`failed`; run `create` again. Concurrent operations on the same domain are blocked by a lock file.

## Faster creates: clone a golden database

A normal `create` runs the installer, and loading the schema is about 90% of its time. With a golden
database, a new practice is a server-side copy of one finished install instead:

```bash
php provision-practice.php build-golden                  # once, and again after every OpenEMR upgrade
php provision-practice.php create --spec=practice.json --clone
```

`build-golden` installs a reference database (`oe_golden`) with a placeholder admin and records the
version it was built for. `create --clone` copies every table, checks each table's row count, gives
the copy the practice's own admin, name and settings, and then carries on with the same steps as an
install (specialty seed, modules, first-login prompt, verification).

- **It refuses a stale golden database.** If the code version no longer matches the one recorded,
  nothing is created and it tells you to run `build-golden` again, so no practice starts out behind.
- **What I checked** (local Docker, one clone against the golden database and an installer-made
  practice): identical schema, no leftover placeholder names, the same access-control rows, and the
  admin can log in. A fresh database has no UUIDs and no encrypted values, so there is nothing to
  regenerate; if a future version adds some, `personalizeClone()` must handle them.
- **Speed.** Locally an install takes about 123 seconds and a clone about 58, of which 41 are the 286
  `CREATE TABLE` statements (about 145 ms each on Docker Desktop for Windows) and 12 are the data
  copy. Those are disk-bound numbers; measure again on the real database server before relying on
  them.
- `golden.state` lives in the state directory. Practices record whether they were `install`ed or
  `clone`d in their state file.

## Admin onboarding

Each practice gets a random initial password for its first admin. How it reaches that person is up
to whatever drives this script (the future dashboard): do not email it in plain text.

- By default `create` prints it in the JSON result. With `--secrets-file=PATH` it is written to a new
  owner-only file instead and left out of the output and logs. The file is created before anything
  else changes and the path must not already exist.
- **First login.** OpenEMR has no "must change at first login" lock for staff, but it does open a
  "Password Reset" tab first when a password is older than the practice's expiry setting (180 days by
  default). `create` dates the admin's password as if it were that old, so the admin is prompted
  straight away. It is a prompt, not a lock: it can be dismissed during the 30-day grace period. The
  login check in `--verify-url` confirms the tab really appears. Set
  `"admin": {"require_password_change": false}` to skip it, and note that a practice with password
  expiry switched off (0 days) cannot be prompted.
- **Lost or undelivered password:** `reset-admin <domain> [--username=NAME] [--secrets-file=PATH]`
  sets a new random password, clears the failed-login counters and password history, and re-arms the
  first-login prompt. `--username` is only needed for practices created before the admin name was
  recorded in the state file.
- No invite links: OpenEMR has none for staff (its one-time links are for patients in the portal).
  Building them is a job for the dashboard, which can call `reset-admin` and deliver the result.

## Site template

New practices start from [site-template/](site-template/), not from `sites/default`. It holds only
files committed to git (built with `git archive`), so documents, keys, logos or settings that a live
site has collected can never be copied into a new practice. It has no `sqlconf.php`, and the
provisioner refuses any template directory that has one, which also stops it being pointed at a live
site by mistake. Edit and commit files there to change what every new practice starts with.

## Modules

`"modules": ["oe-module-dashboard-context", ...]` lists module directory names (under
`interface/modules/custom_modules/` or `zend_modules/module/`). Names are checked before the install
starts. For each one the script runs `bin/console openemr:zfc-module --site=<domain>` (`discover`,
then `install`, then `enable`) and verifies the module is active in that practice's database. Modules
are per practice: enabling one never affects another practice.

## Practice settings that start switched off

A new practice has the REST API, FHIR API, patient-portal API and portal settings **off**. Turn on
what a practice needs through `globals`, for example:

```json
"globals": {"rest_api": "1", "rest_fhir_api": "1", "rest_portal_api": "1",
            "portal_onsite_two_enable": "1", "site_addr_oath": "https://practice-a.example.com"}
```

## Upgrading every practice

After deploying a new release, each practice database still has the old schema:

```bash
php upgrade-practices.php --backup-dir=/backups --dry-run   # see who is behind
php upgrade-practices.php --backup-dir=/backups             # back up, then upgrade each practice
```

Use `--no-backup` instead of `--backup-dir` only when something else (for example an RDS snapshot)
has already taken one, and `--site=<domain>` to do a single practice. A practice whose database is
newer than the code is skipped, never downgraded. Each practice is verified after its upgrade and the
exit code is 1 if any failed. `site-upgrade.php` is the helper that points the stock `sql_upgrade.php`
at one site (it otherwise only ever upgrades `default` from the command line).

## API settings and signing keys (FHIR and SMART)

Every new practice is created with the REST and FHIR APIs on (`rest_api`, `rest_fhir_api`), the portal API,
backend (`system/*`) tokens and the password grant off, and `site_addr_oath` set to `https://<domain>`
(`PROVISION_PUBLIC_SCHEME=http` in a local test stack). A specialty template or the spec's `globals` can override.

```bash
php provision-practice.php apply-api-defaults <domain>   # same settings, for a practice that already exists
php provision-practice.php rotate-oauth-keys  <domain>   # new OAuth signing keys; older tokens stop working
```

Each practice keeps its own signing keys in `sites/<domain>/documents/certificates/`, created on first OAuth use.
The site template contains none.

**How FHI-01 was verified** (local stack only; the password grant is switched on for the test and off again):
1. Create two practices; check both discovery documents answer (`/oauth2/<domain>/.well-known/openid-configuration`
   and `/apis/<domain>/fhir/.well-known/smart-configuration`).
2. Register an app (`POST /oauth2/<domain>/registration`). It starts disabled: an admin enables it, and an admin
   adds the `password` grant (`oauth_clients.is_enabled`, `grant_types`), which a client cannot do for itself.
3. Get a token with the password grant, create a patient through `/apis/<domain>/api/patient`, read it back
   through `/apis/<domain>/fhir/Patient`.
4. Present practice A's token to practice B: it must be refused (401). Compare
   `md5sum sites/<domain>/documents/certificates/oaprivate.key` across practices: all different.

Not covered: the interactive SMART launch with a browser (needs HTTPS and a real domain, INF-05).

## Background jobs

Per-practice scheduled work already supports `--site`:

```bash
php bin/console background:services list    --site=<domain>
php bin/console background:services crontab --site=<domain>   # prints ready-made cron lines
```

## Known limits

- No DNS or certificate handling, no secrets-manager integration: the database password lives in
  `sites/<domain>/sqlconf.php`.
- A failed run is retried from the start (about two minutes), not resumed.
- `upgrade-practices.php` only knows practices created by `provision-practice.php` (it reads their
  state files). The upgrade path has been tested by re-applying the 8.4.1 to 8.5.0 step to an 8.5.0
  database, not across a real schema change.
