<?php

/**
 * Per-practice platform settings (table platform_setting), with defaults for anything not set.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Platform\Settings;

use OpenEMR\Common\Database\QueryUtils;

final class SettingsRepository
{
    public const APPROVAL_TTL_MINUTES = 'approval_ttl_minutes';
    public const API_LOG_RETENTION_DAYS = 'api_log_retention_days';

    /** @var array<string, int> */
    private const DEFAULTS = [
        self::APPROVAL_TTL_MINUTES => 1440,
        self::API_LOG_RETENTION_DAYS => 90,
    ];

    public function getInt(string $name): int
    {
        if (!array_key_exists($name, self::DEFAULTS)) {
            throw new \InvalidArgumentException('Unknown setting');
        }
        $rows = QueryUtils::fetchRecords('SELECT value FROM platform_setting WHERE name = ?', [$name], true);
        if ($rows === [] || !is_numeric($rows[0]['value'])) {
            return self::DEFAULTS[$name];
        }
        return (int) $rows[0]['value'];
    }

    public function setInt(string $name, int $value): void
    {
        if (!array_key_exists($name, self::DEFAULTS) || $value < 1) {
            throw new \InvalidArgumentException('Unknown setting or invalid value');
        }
        QueryUtils::sqlStatementThrowException(
            'INSERT INTO platform_setting (name, value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()',
            [$name, (string) $value],
            true
        );
    }
}
