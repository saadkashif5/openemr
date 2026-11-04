<?php

/**
 * Run sql_upgrade.php against one named site from the command line.
 *
 * sql_upgrade.php forces the "default" site in CLI mode, so on its own it can never upgrade a
 * practice. This wrapper selects the site the same way the browser does (a "site" query value),
 * then hands over to the stock script. Used by upgrade-practices.php.
 *
 *   php site-upgrade.php <site> --from=<major.minor.patch of the site's current database>
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !isset($argv[1], $argv[2]) || !str_starts_with($argv[2], '--from=')) {
    fwrite(STDERR, "Usage: php site-upgrade.php <site> --from=<version>\n");
    exit(2);
}
$site = $argv[1];
if (!preg_match('/^[a-z0-9]+(?:[.-][a-z0-9]+)*$/', $site) || !preg_match('/^--from=\d+\.\d+\.\d+$/', $argv[2])) {
    fwrite(STDERR, "Invalid site or version\n");
    exit(2);
}

// Legacy bridge: globals.php reads the site from the query string before anything else.
$_GET['site'] = $site;
// sql_upgrade.php looks for --from= in $argv, so drop the site argument.
$argv = [$argv[0], $argv[2]];
chdir(dirname(__DIR__, 3));
require 'sql_upgrade.php';
