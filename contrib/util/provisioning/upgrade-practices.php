<?php

/**
 * Upgrade the database of every provisioned practice to match the code, one practice at a time.
 *
 * After deploying a new OpenEMR release, each practice database still has the old schema. This
 * finds practices that are behind, takes a backup, runs the stock upgrade for that site only, and
 * checks the version afterwards. A practice whose database is NEWER than the code is never touched.
 *
 *   php upgrade-practices.php (--backup-dir=DIR | --no-backup) [--site=DOMAIN] [--dry-run]
 *
 * Needs the same environment as provision-practice.php (OPENEMR_ENABLE_PROVISIONING=1, database
 * root password in PROVISION_DB_ROOT_PASS or MYSQL_ROOT_PASS, run as the web server user).
 * Logs go to stderr, the result is JSON on stdout. Exit codes: 0 all fine, 1 a practice failed, 2 usage.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (getenv('OPENEMR_ENABLE_PROVISIONING') !== '1') {
    fwrite(STDERR, "Set OPENEMR_ENABLE_PROVISIONING=1 to enable this script\n");
    exit(2);
}
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "Refusing to run as root\n");
    exit(2);
}

$root = dirname(__DIR__, 3);

/** @return array<string, string> */
function options(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $arg, $m)) {
            $out[$m[1]] = $m[2] ?? '1';
        }
    }
    return $out;
}

function usage(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(2);
}

/** @return array{major: string, minor: string, patch: string, database: int} */
function codeVersion(string $root): array
{
    return (function () use ($root): array {
        require $root . '/version.php';
        /** @var string $v_major */
        /** @var string $v_minor */
        /** @var string $v_patch */
        /** @var int $v_database */
        return ['major' => $v_major, 'minor' => $v_minor, 'patch' => $v_patch, 'database' => (int) $v_database];
    })();
}

$opts = options($argv);
$backupDir = $opts['backup-dir'] ?? null;
if ($backupDir === null && !isset($opts['no-backup'])) {
    usage('Choose --backup-dir=DIR or explicitly --no-backup');
}
if ($backupDir !== null && !is_dir($backupDir) && !mkdir($backupDir, 0770, true)) {
    usage("Cannot create backup directory $backupDir");
}
$dryRun = isset($opts['dry-run']);
$only = $opts['site'] ?? null;

$host = getenv('PROVISION_DB_HOST') ?: (getenv('MYSQL_HOST') ?: 'localhost');
$port = (int) (getenv('PROVISION_DB_PORT') ?: 3306);
$rootUser = getenv('PROVISION_DB_ROOT_USER') ?: 'root';
$rootPass = getenv('PROVISION_DB_ROOT_PASS') ?: (getenv('MYSQL_ROOT_PASS') ?: '');
$stateDir = getenv('PROVISION_STATE_DIR') ?: $root . '/sites/.provisioning';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$target = codeVersion($root);
$targetLabel = "{$target['major']}.{$target['minor']}.{$target['patch']}";
fwrite(STDERR, "Code version $targetLabel (database {$target['database']})\n");

$results = [];
foreach (glob($stateDir . '/*.json') ?: [] as $file) {
    $state = json_decode((string) file_get_contents($file), true);
    if (!is_array($state) || ($state['status'] ?? '') !== 'active') {
        continue;
    }
    $domain = (string) $state['domain'];
    $dbName = (string) $state['db_name'];
    if ($only !== null && $only !== $domain) {
        continue;
    }
    $row = ['domain' => $domain];

    try {
        $link = new mysqli($host, $rootUser, $rootPass, $dbName, $port);
        $read = static function () use ($link): array {
            $r = $link->query('SELECT v_major, v_minor, v_patch, v_database FROM version LIMIT 1')->fetch_assoc();
            return is_array($r) ? $r : throw new RuntimeException('version table is empty');
        };
        $before = $read();
        $row['from'] = "{$before['v_major']}.{$before['v_minor']}.{$before['v_patch']}";
        $row['database_version_before'] = (int) $before['v_database'];

        if ((int) $before['v_database'] > $target['database']) {
            $row['result'] = 'skipped: database is newer than the code, deploy matching code first';
            $results[] = $row;
            continue;
        }
        if ((int) $before['v_database'] === $target['database'] && $row['from'] === $targetLabel) {
            $row['result'] = 'current';
            $results[] = $row;
            continue;
        }
        if ($dryRun) {
            $row['result'] = "would upgrade to $targetLabel";
            $results[] = $row;
            continue;
        }

        if ($backupDir !== null) {
            $backupFile = sprintf('%s/%s-%s-before-%s.sql.gz', rtrim($backupDir, '/'), $domain, gmdate('Ymd-His'), $targetLabel);
            backup($host, $port, $rootUser, $rootPass, $dbName, $backupFile);
            $row['backup'] = $backupFile;
        }

        fwrite(STDERR, "Upgrading $domain from {$row['from']}\n");
        $proc = proc_open(
            [PHP_BINARY, __DIR__ . '/site-upgrade.php', $domain, '--from=' . $row['from']],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $out = is_resource($proc) ? (string) stream_get_contents($pipes[1]) : '';
        $code = is_resource($proc) ? proc_close($proc) : 1;

        $after = $read();
        $afterLabel = "{$after['v_major']}.{$after['v_minor']}.{$after['v_patch']}";
        if ($code !== 0 || (int) $after['v_database'] !== $target['database'] || $afterLabel !== $targetLabel) {
            throw new RuntimeException("Upgrade did not reach $targetLabel (now $afterLabel, exit $code)");
        }
        $row['result'] = 'upgraded';
        $row['to'] = $afterLabel;
        $link->close();
    } catch (Throwable $e) {
        $row['result'] = 'FAILED: ' . $e->getMessage();
        fwrite(STDERR, "Practice $domain failed: " . $e->getMessage() . "\n");
    }
    $results[] = $row;
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
exit(array_filter($results, fn(array $r): bool => str_starts_with((string) $r['result'], 'FAILED')) === [] ? 0 : 1);

function backup(string $host, int $port, string $user, string $pass, string $db, string $file): void
{
    $dump = '/usr/bin/mariadb-dump';
    if (!is_executable($dump)) {
        $dump = '/usr/bin/mysqldump';
    }
    $proc = proc_open(
        [$dump, '--single-transaction', '--routines', "--host=$host", "--port=$port", "--user=$user", $db],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        ['MYSQL_PWD' => $pass]
    );
    $gz = gzopen($file, 'wb6');
    if (!is_resource($proc) || $gz === false) {
        throw new RuntimeException('Cannot start the backup');
    }
    while (!feof($pipes[1])) {
        gzwrite($gz, (string) fread($pipes[1], 1 << 20));
    }
    gzclose($gz);
    $err = stream_get_contents($pipes[2]);
    if (proc_close($proc) !== 0 || filesize($file) < 1000) {
        @unlink($file);
        throw new RuntimeException('Backup failed, upgrade not attempted: ' . trim((string) $err));
    }
}
