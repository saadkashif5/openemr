<?php

/**
 * Delete old API log rows for one practice, using its retention setting (default 90 days).
 *
 *   php purge-api-log.php <site>
 *
 * Run it from cron or the worker, once per practice. Prints how many rows were removed.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !isset($argv[1]) || preg_match('/^[a-z0-9]+(?:[.-][a-z0-9]+)*$/', $argv[1]) !== 1) {
    fwrite(STDERR, "Usage: php purge-api-log.php <site>\n");
    exit(2);
}

// Legacy bridge: globals.php reads the site from the query string before anything else.
$_GET['site'] = $argv[1];
$ignoreAuth = true;
require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Modules\Platform\Retention\ApiLogRetention;
use OpenEMR\Modules\Platform\Settings\SettingsRepository;

$removed = (new ApiLogRetention(new SettingsRepository(), ServiceContainer::getClock()))->purge();
echo json_encode(['site' => $argv[1], 'removed' => $removed]), "\n";
