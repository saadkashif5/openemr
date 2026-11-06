# oe-module-platform

EMB platform module. Provisioning installs and enables it in every new practice (see
`contrib/util/provisioning/provision-practice.php`), whether or not the practice's spec lists it.

It will hold the endpoints used by the React screens and the shell launcher page. Today it has one endpoint:

| Endpoint | Method | Result |
|---|---|---|
| `/interface/modules/custom_modules/oe-module-platform/public/health.php` | GET, HEAD | `200` with `{"status":"ok","authenticated":true,"user":{"id","username"},"module","version","site"}` when signed in; `401` with `{"authenticated":false}` when not; `405` for other methods. Never cached. |

Tests: `tests/Tests/Isolated/Modules/Platform/HealthServiceTest.php`.
