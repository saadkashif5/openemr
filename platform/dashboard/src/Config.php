<?php

declare(strict_types=1);

namespace Platform\Dashboard;

use RuntimeException;

/** Paths and the dashboard database connection details, read from var/config.php (written by bin/setup.php). */
final readonly class Config
{
    /** @param array{host: string, port: int, name: string, user: string, pass: string} $db */
    public function __construct(
        public string $root,
        public string $appDir,
        public array $db,
        public string $stateDir,
        public string $secretsDir,
        public string $tmpDir,
        public string $verifyUrl,
        public bool $secureCookies = false,
        public ?string $platformDomain = null,
        public string $practiceScheme = 'https',
        public ?int $practicePort = null,
    ) {
    }

    public static function load(): self
    {
        $appDir = dirname(__DIR__);
        $file = $appDir . '/var/config.php';
        if (!is_file($file)) {
            throw new RuntimeException('Not set up yet: run bin/setup.php');
        }
        $data = require $file;
        if (!is_array($data) || !isset($data['db']) || !is_array($data['db'])) {
            throw new RuntimeException('var/config.php is invalid');
        }
        $root = dirname($appDir, 2);
        return new self(
            $root,
            $appDir,
            [
                'host' => (string) $data['db']['host'],
                'port' => (int) $data['db']['port'],
                'name' => (string) $data['db']['name'],
                'user' => (string) $data['db']['user'],
                'pass' => (string) $data['db']['pass'],
            ],
            getenv('PROVISION_STATE_DIR') ?: $root . '/sites/.provisioning',
            $appDir . '/var/secrets',
            $appDir . '/var/tmp',
            is_string($data['verify_url'] ?? null) ? $data['verify_url'] : 'http://localhost',
            ($data['secure_cookies'] ?? false) === true,
            is_string($data['platform_domain'] ?? null) && $data['platform_domain'] !== '' ? strtolower($data['platform_domain']) : null,
            ($data['practice_scheme'] ?? 'https') === 'http' ? 'http' : 'https',
            is_int($data['practice_port'] ?? null) && $data['practice_port'] > 0 ? $data['practice_port'] : null,
        );
    }

    /**
     * One-click open only works for a practice on the same site as this dashboard, because a practice's session
     * cookie is SameSite=Strict: <slug>.<platform-domain> qualifies, a customer's own domain does not (yet).
     */
    public function isOnPlatformDomain(string $practiceDomain): bool
    {
        return $this->platformDomain !== null
            && str_ends_with($practiceDomain, '.' . $this->platformDomain)
            && !str_starts_with($practiceDomain, 'admin.');
    }

    /** The practice's origin, for example https://clinic.example.com (a port only when configured, for local stacks). */
    public function practiceOrigin(string $practiceDomain): string
    {
        return $this->practiceScheme . '://' . $practiceDomain . ($this->practicePort !== null ? ':' . $this->practicePort : '');
    }
}
