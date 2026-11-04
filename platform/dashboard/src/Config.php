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
        );
    }
}
