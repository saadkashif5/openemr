# Core patches

Our code lives in `platform/`, `contrib/util/provisioning/`, `frontend/` and `oe-module-platform` (decision D-02). A few
changes had to touch OpenEMR's own files. Each is small, marked in the code with `EMB patch` where practical, and listed
here so it can be re-applied after an OpenEMR upgrade (`git diff <old release> -- <file>` shows each one).

| # | File | Change | Why |
|---|---|---|---|
| 1 | `index.php` | Strip a `:port` from the `Host` header before matching it to a site folder | `practice-a.localhost:8300` must find `sites/practice-a.localhost`; only matters in a local stack with a port |
| 2 | `interface/modules/zend_modules/module/Installer/src/Installer/Controller/InstallerController.php` | `notifyModuleListener()` made public; new `getCustomModuleDirectory()` | The console command must run a module's listener hooks the way the Module Manager does |
| 3 | `src/Common/Command/ZfcModule.php` | Calls a custom module's pre and post listener hooks on each action | Same reason: modules enabled by provisioning must be configured as if enabled in the UI |
| 4 | `interface/usergroup/usergroup_admin.php` (the Users list query, `SELECT * FROM users ... `) | Adds `AND username != 'platform-admin'` | The hidden platform account must not appear in the Users list (D-05, TEN-04) |
| 5 | `interface/usergroup/user_admin.php` (after the user row is read) | `exit()` when the row is `platform-admin` | The account must not open in the edit page even by typing its id (TEN-04) |

Patches 4 and 5 only hide the account; the **database triggers** are what stop it being changed or removed (see
`contrib/util/provisioning/README.md`, "The platform-admin account").

**Known gaps in hiding** (the guard still holds, but the account can be seen): the access-control (phpGACL) screens list
every user alias in a group, and the practice audit log records the account's activity under its name. The audit log is left
visible on purpose, so a practice can see when the platform account was used.

**After an OpenEMR upgrade:** re-check each file above for conflicts, then run
`php provision-practice.php verify-platform-admin <domain>` for every practice; it reports a missing or weakened guard.
