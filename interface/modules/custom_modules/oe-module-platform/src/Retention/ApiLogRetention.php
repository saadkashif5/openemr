<?php

/**
 * Keeps the API log (api_log) from growing without limit. It stores request and response bodies, which can be
 * copies of patient data, so how long to keep them is a deliberate setting (default 90 days).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Platform\Retention;

use DateTimeImmutable;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\Platform\Settings\SettingsRepository;
use Psr\Clock\ClockInterface;

final readonly class ApiLogRetention
{
    public function __construct(private SettingsRepository $settings, private ClockInterface $clock)
    {
    }

    /** Delete API log rows older than the practice's retention setting. Returns how many were removed. */
    public function purge(): int
    {
        $days = $this->settings->getInt(SettingsRepository::API_LOG_RETENTION_DAYS);
        $cutoff = $this->clock->now()->modify("-{$days} days");
        return $this->purgeBefore($cutoff);
    }

    public function purgeBefore(DateTimeImmutable $cutoff): int
    {
        QueryUtils::sqlStatementThrowException('DELETE FROM api_log WHERE created_time < ?', [$cutoff->format('Y-m-d H:i:s')], true);
        return (int) QueryUtils::affectedRows();
    }
}
