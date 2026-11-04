<?php

declare(strict_types=1);

namespace Platform\Dashboard;

/**
 * Reads the provisioning records (sites/.provisioning/*.json) to show every practice. The web side
 * only reads files: it never connects to a practice database.
 */
final class Practices
{
    public const DOMAIN_PATTERN = '/^(?=.{1,100}$)[a-z0-9]+(?:[.-][a-z0-9]+)*$/';

    public function __construct(private readonly Config $config)
    {
    }

    public static function validDomain(string $domain): bool
    {
        return preg_match(self::DOMAIN_PATTERN, $domain) === 1;
    }

    /** @return array<string, mixed>|null */
    public function find(string $domain): ?array
    {
        // The domain becomes part of a file path, so it must pass the strict pattern first.
        if (!self::validDomain($domain)) {
            return null;
        }
        $file = $this->config->stateDir . '/' . $domain . '.json';
        $state = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        return is_array($state) ? $state : null;
    }

    /** @return list<array<string, mixed>> practices that have a record, newest first */
    public function all(): array
    {
        $out = [];
        foreach (glob($this->config->stateDir . '/*.json') ?: [] as $file) {
            $state = json_decode((string) file_get_contents($file), true);
            if (is_array($state) && isset($state['domain'])) {
                $out[] = $state;
            }
        }
        usort($out, static fn(array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        return $out;
    }

    /** @return array{label: string, database: int} */
    public function codeVersion(): array
    {
        $root = $this->config->root;
        return (function () use ($root): array {
            require $root . '/version.php';
            /** @var string $v_major */
            /** @var string $v_minor */
            /** @var string $v_patch */
            /** @var int|string $v_database */
            return ['label' => "$v_major.$v_minor.$v_patch", 'database' => (int) $v_database];
        })();
    }

    /** @return array{status: string, version: ?string, built_at: ?string, code: string} */
    public function golden(): array
    {
        $code = $this->codeVersion();
        $file = $this->config->stateDir . '/golden.state';
        $state = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($state)) {
            return ['status' => 'missing', 'version' => null, 'built_at' => null, 'code' => $code['label']];
        }
        $current = ($state['version'] ?? '') === $code['label'] && (int) ($state['database'] ?? 0) === $code['database'];
        return [
            'status' => $current ? 'current' : 'stale',
            'version' => (string) ($state['version'] ?? ''),
            'built_at' => (string) ($state['built_at'] ?? ''),
            'code' => $code['label'],
        ];
    }

    /** @return list<string> specialty templates available to choose from */
    public function specialties(): array
    {
        $dirs = glob($this->config->root . '/contrib/util/provisioning/templates/*', GLOB_ONLYDIR) ?: [];
        $names = array_map('basename', $dirs);
        sort($names);
        return $names;
    }

    /** @return list<string> custom modules that can be enabled for a practice */
    public function modules(): array
    {
        $dirs = glob($this->config->root . '/interface/modules/custom_modules/oe-module-*', GLOB_ONLYDIR) ?: [];
        $names = array_map('basename', $dirs);
        sort($names);
        return $names;
    }
}
