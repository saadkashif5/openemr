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
4. Apply the specialty template, if `templates/<specialty>/` exists:
   `globals.json` (settings) and `site/` (files copied over the new site directory, e.g. `LBF/`).
5. Set the admin email.
6. Verify in the database (admin exists, has a password, is in an access-control group) and, with
   `--verify-url`, fetch the login page and sign in as the new admin.

If any step fails, the database, user and site directory are removed and the practice is recorded as
`failed`; run `create` again. Concurrent operations on the same domain are blocked by a lock file.

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
