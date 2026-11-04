<?php

/**
 * A validated description of a practice to create. Shared by provision-practice.php and the
 * super-admin dashboard so both apply exactly the same rules.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

/** A validated description of the practice to create. */
final readonly class PracticeSpec
{
    /**
     * @param array<string, string> $globals
     * @param list<string> $modules
     */
    private function __construct(
        public string $domain,
        public string $name,
        public string $adminUser,
        public string $adminFirst,
        public string $adminLast,
        public string $adminEmail,
        public bool $requirePasswordChange,
        public string $specialty,
        public array $globals,
        public array $modules,
        public string $dbName,
        public string $dbUser,
    ) {
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $domain = self::str($data, 'domain');
        if (!preg_match('/^(?=.{1,100}$)[a-z0-9]+(?:[.-][a-z0-9]+)*$/', $domain) || $domain === 'default') {
            throw new InvalidArgumentException("domain must be lowercase letters, digits, dots and hyphens (and not 'default')");
        }
        // Any host starting "admin." is routed to the super-admin dashboard (see the top-level .htaccess).
        if ($domain === 'admin' || str_starts_with($domain, 'admin.')) {
            throw new InvalidArgumentException("domains starting with 'admin.' are reserved for the platform dashboard");
        }
        $name = self::str($data, 'name');
        if ($name === '' || strlen($name) > 100) {
            throw new InvalidArgumentException('name is required (max 100 characters)');
        }
        // Names are shown inside OpenEMR's own pages, so keep markup characters out of them.
        if (preg_match('/[<>]/', $name)) {
            throw new InvalidArgumentException('name must not contain < or >');
        }
        $admin = $data['admin'] ?? null;
        if (!is_array($admin)) {
            throw new InvalidArgumentException('admin is required');
        }
        $adminUser = self::str($admin, 'username');
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $adminUser)) {
            throw new InvalidArgumentException('admin.username must be 3-50 characters of A-Z a-z 0-9 . _ -');
        }
        $adminLast = self::str($admin, 'last_name');
        if ($adminLast === '') {
            throw new InvalidArgumentException('admin.last_name is required');
        }
        if (preg_match('/[<>]/', $adminLast . self::str($admin, 'first_name'))) {
            throw new InvalidArgumentException('admin names must not contain < or >');
        }
        $adminEmail = self::str($admin, 'email');
        if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('admin.email is not a valid email address');
        }
        $requireChange = $admin['require_password_change'] ?? true;
        if (!is_bool($requireChange)) {
            throw new InvalidArgumentException('admin.require_password_change must be true or false');
        }
        $specialty = self::str($data, 'specialty');
        if ($specialty !== '' && !preg_match('/^[a-z0-9_-]+$/', $specialty)) {
            throw new InvalidArgumentException('specialty must be lowercase letters, digits, _ or -');
        }
        $modules = $data['modules'] ?? [];
        if (!is_array($modules) || !array_is_list($modules)) {
            throw new InvalidArgumentException('modules must be a list of module directory names');
        }
        foreach ($modules as $module) {
            if (!is_string($module) || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $module) || str_contains($module, '..')) {
                throw new InvalidArgumentException('each module must be a directory name such as oe-module-weno');
            }
        }
        $globals = [];
        $rawGlobals = $data['globals'] ?? [];
        if (!is_array($rawGlobals)) {
            throw new InvalidArgumentException('globals must be an object of name: value');
        }
        foreach ($rawGlobals as $key => $value) {
            if (!is_string($key) || !preg_match('/^[A-Za-z0-9_]+$/', $key) || !is_scalar($value)) {
                throw new InvalidArgumentException('globals must map names (A-Z a-z 0-9 _) to scalar values');
            }
            $globals[$key] = (string) $value;
        }

        // Derived names: the hash keeps "a-b.com" and "a.b.com" apart; lengths fit MySQL's user/db limits.
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', $domain), '_');
        $hash = substr(sha1($domain), 0, 6);
        return new self(
            $domain,
            $name,
            $adminUser,
            self::str($admin, 'first_name'),
            $adminLast,
            $adminEmail,
            $requireChange,
            $specialty,
            $globals,
            array_values(array_unique($modules)),
            'oe_' . substr($slug, 0, 40) . '_' . $hash,
            'u_' . substr($slug, 0, 20) . '_' . $hash,
        );
    }

    /** @param array<mixed> $data */
    private static function str(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        if (!is_string($value)) {
            throw new InvalidArgumentException("$key must be a string");
        }
        return trim($value);
    }
}
