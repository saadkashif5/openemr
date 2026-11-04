<?php

/**
 * Provision, inspect and destroy OpenEMR practices (multisite "sites").
 *
 * Each practice is one site: sites/<domain>/ plus its own database and database user. This wraps the
 * stock Installer so a practice can be created from a JSON spec without the setup wizard.
 *
 *   php provision-practice.php build-golden
 *   php provision-practice.php create  --spec=practice.json [--clone] [--verify-url=http://localhost] [--secrets-file=PATH]
 *   php provision-practice.php status  <domain>
 *   php provision-practice.php list
 *   php provision-practice.php reset-admin <domain> [--username=NAME] [--secrets-file=PATH]
 *   php provision-practice.php destroy <domain> --confirm=<domain>
 *
 * Safety: set OPENEMR_ENABLE_PROVISIONING=1 to enable, and run as the web server user (not root).
 * The database root password is read from PROVISION_DB_ROOT_PASS (or MYSQL_ROOT_PASS), never from
 * the spec or the command line. Logs go to stderr; the result is a single JSON document on stdout.
 *
 * "create" is all-or-nothing: if any step fails, the database, database user and site directory it
 * created are removed again and the practice is recorded as failed, so it can simply be re-run.
 *
 * Exit codes: 0 ok, 1 failed, 2 bad usage or spec, 3 practice already exists.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (getenv('OPENEMR_ENABLE_PROVISIONING') !== '1') {
    fwrite(STDERR, "Set OPENEMR_ENABLE_PROVISIONING=1 to enable this script\n");
    exit(2);
}
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "Refusing to run as root: run as the web server user so site files are owned correctly\n");
    exit(2);
}

$openemrRoot = dirname(__DIR__, 3);
require_once $openemrRoot . '/vendor/autoload.php';

/** Connection details for the shared database server. */
final readonly class DbServer
{
    public function __construct(
        public string $host,
        public int $port,
        public string $rootUser,
        public string $rootPass,
        public string $loginHost,
    ) {
    }

    public function connect(?string $user = null, ?string $pass = null, ?string $db = null): mysqli
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $link = new mysqli($this->host, $user ?? $this->rootUser, $pass ?? $this->rootPass, $db, $this->port);
        $link->set_charset('utf8mb4');
        return $link;
    }
}

require_once __DIR__ . '/PracticeSpec.php';

final class Provisioner
{
    private readonly string $sitesDir;

    public function __construct(
        private readonly string $root,
        private readonly string $stateDir,
        private readonly DbServer $db,
        private readonly string $templateSite,
        private readonly string $templatesDir,
        private readonly LoggerInterface $logger,
    ) {
        $this->sitesDir = $root . '/sites';
        if (!is_dir($stateDir) && !mkdir($stateDir, 0770, true) && !is_dir($stateDir)) {
            throw new RuntimeException("Cannot create state directory $stateDir");
        }
    }

    /** @return array<string, mixed> */
    public function create(PracticeSpec $spec, ?string $verifyUrl, ?string $secretsFile = null, bool $clone = false): array
    {
        $lock = $this->lock($spec->domain);
        try {
            $existing = $this->readState($spec->domain);
            if ($existing !== null && ($existing['status'] ?? '') === 'active') {
                throw new PracticeExistsException("Practice {$spec->domain} is already provisioned");
            }
            $this->assertNothingLeftBehind($spec);
            if ($clone) {
                $this->assertGoldenCurrent();
            }

            $state = [
                'domain' => $spec->domain,
                'name' => $spec->name,
                'specialty' => $spec->specialty,
                'status' => 'provisioning',
                'db_name' => $spec->dbName,
                'db_user' => $spec->dbUser,
                'db_host' => $this->db->host,
                'admin_user' => $spec->adminUser,
                'method' => $clone ? 'clone' : 'install',
                'created_at' => gmdate('c'),
                'steps' => [],
            ];
            $this->saveState($state);
            $this->audit('create.start', $spec->domain);

            $dbPass = self::secret(32);
            $adminPass = self::secret(20);
            $promptArmed = false;
            $secretsHandle = self::reserveSecretsFile($secretsFile);
            try {
                if ($clone) {
                    $this->step($state, 'clone', fn() => $this->cloneFromGolden($spec, $dbPass, $adminPass));
                } else {
                    $this->step($state, 'install', fn() => $this->install($spec, $dbPass, $adminPass));
                }
                $this->step($state, 'seed', fn() => $this->applySeed($spec, $dbPass));
                $this->step($state, 'overlay', fn() => $this->applyOverlay($spec));
                $this->step($state, 'modules', fn() => $this->enableModules($spec));
                $this->step($state, 'admin_profile', fn() => $this->setAdminProfile($spec, $dbPass));
                $this->step($state, 'first_login_prompt', function () use ($spec, $dbPass, &$promptArmed): void {
                    $promptArmed = $this->armPasswordChange($spec, $dbPass);
                });
                $this->step($state, 'verify_database', fn() => $this->verifyDatabase($spec, $dbPass));
                if ($verifyUrl !== null) {
                    $this->step($state, 'verify_login', fn() => $this->verifyLogin($verifyUrl, $spec, $adminPass, $promptArmed));
                }
            } catch (Throwable $e) {
                $this->logger->error('Provisioning {domain} failed, rolling back', [
                    'domain' => $spec->domain,
                    'exception' => $e,
                ]);
                $this->rollback($spec);
                self::discardSecretsFile($secretsHandle, $secretsFile);
                $state['status'] = 'failed';
                $state['error'] = $e->getMessage();
                $state['updated_at'] = gmdate('c');
                $this->saveState($state);
                $this->audit('create.failed', $spec->domain);
                throw $e;
            }

            $state['status'] = 'active';
            $state['updated_at'] = gmdate('c');
            $this->saveState($state);
            $this->audit('create.ok', $spec->domain);

            return [
                'domain' => $spec->domain,
                'status' => 'active',
                'login_path' => "/interface/login/login.php?site={$spec->domain}",
                'database' => ['name' => $spec->dbName, 'user' => $spec->dbUser, 'host' => $this->db->host],
                // Shown once and never stored: hand it to the practice admin securely.
                'admin' => self::deliverSecret($spec->adminUser, $adminPass, $secretsHandle, $secretsFile),
                'first_login' => $promptArmed
                    ? 'The admin is asked to change the password on first login'
                    : 'The initial password stays valid until it expires: no first-login prompt',
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string, mixed> */
    public function destroy(string $domain): array
    {
        $lock = $this->lock($domain);
        try {
            $state = $this->readState($domain);
            if ($state === null) {
                throw new RuntimeException("No provisioning record for $domain: refusing to guess which database to drop");
            }
            $spec = PracticeSpec::fromArray([
                'domain' => $domain,
                'name' => (string) ($state['name'] ?? 'x'),
                'admin' => ['username' => 'unused', 'last_name' => 'x'],
            ]);
            $this->audit('destroy.start', $domain);
            $this->rollback($spec);
            $state['status'] = 'destroyed';
            $state['updated_at'] = gmdate('c');
            $this->saveState($state);
            $this->audit('destroy.ok', $domain);
            return ['domain' => $domain, 'status' => 'destroyed'];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string, mixed>|null */
    public function status(string $domain): ?array
    {
        return $this->readState($domain);
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $out = [];
        foreach (glob($this->stateDir . '/*.json') ?: [] as $file) {
            $state = json_decode((string) file_get_contents($file), true);
            if (is_array($state)) {
                $out[] = $state;
            }
        }
        return $out;
    }

    /**
     * Settings written into a new practice: the specialty's, then the spec's own, then its name.
     *
     * @return array<string, array{value: string}>
     */
    private function customGlobals(PracticeSpec $spec): array
    {
        $globals = [];
        foreach ($this->templateGlobals($spec) as $key => $value) {
            $globals[$key] = ['value' => $value];
        }
        foreach ($spec->globals as $key => $value) {
            $globals[$key] = ['value' => $value];
        }
        $globals['openemr_name'] = ['value' => $spec->name];
        return $globals;
    }

    private function install(PracticeSpec $spec, string $dbPass, string $adminPass): void
    {
        $globals = $this->customGlobals($spec);

        $params = [
            'iuser' => $spec->adminUser,
            'iufname' => $spec->adminFirst,
            'iuname' => $spec->adminLast,
            'iuserpass' => $adminPass,
            'igroup' => $spec->name,
            'server' => $this->db->host,
            'port' => (string) $this->db->port,
            'root' => $this->db->rootUser,
            'rootpass' => $this->db->rootPass,
            'loginhost' => $this->db->loginHost,
            'login' => $spec->dbUser,
            'pass' => $dbPass,
            'dbname' => $spec->dbName,
            'collate' => 'utf8mb4_general_ci',
            'site' => $spec->domain,
            // Must be set: when empty the installer copies "sites/" into itself and recurses forever.
            'source_site_id' => $this->templateSite,
            'custom_globals' => json_encode($globals, JSON_THROW_ON_ERROR),
        ];
        $installer = new Installer($params, $this->logger);
        if (!$installer->quick_install()) {
            throw new RuntimeException('Installer failed: ' . $installer->error_message);
        }
    }

    private const GOLDEN_DB = 'oe_golden';
    private const GOLDEN_USER = 'u_golden';
    private const GOLDEN_SITE = 'golden-build';
    private const GOLDEN_ADMIN = 'golden_admin';
    private const GOLDEN_GROUP = 'Golden';

    /**
     * Install one reference database with the normal installer, to be copied for new practices.
     * Installing is slow (about two minutes, nearly all of it loading the schema); copying a finished
     * database takes seconds. Rebuild it after every OpenEMR upgrade: cloning refuses a stale one.
     *
     * @return array<string, mixed>
     */
    public function buildGolden(): array
    {
        $lock = $this->lock('golden');
        try {
            $started = microtime(true);
            $this->dropDatabase(self::GOLDEN_DB);
            $this->dropDatabaseUser(self::GOLDEN_USER);
            $this->removeSiteDirectory(self::GOLDEN_SITE);
            $this->audit('golden.build.start', 'golden');
            try {
                $installer = new Installer([
                    'iuser' => self::GOLDEN_ADMIN,
                    'iuname' => 'Golden',
                    'iuserpass' => self::secret(24),
                    'igroup' => self::GOLDEN_GROUP,
                    'server' => $this->db->host,
                    'port' => (string) $this->db->port,
                    'root' => $this->db->rootUser,
                    'rootpass' => $this->db->rootPass,
                    'loginhost' => $this->db->loginHost,
                    'login' => self::GOLDEN_USER,
                    'pass' => self::secret(32),
                    'dbname' => self::GOLDEN_DB,
                    'collate' => 'utf8mb4_general_ci',
                    'site' => self::GOLDEN_SITE,
                    'source_site_id' => $this->templateSite,
                ], $this->logger);
                if (!$installer->quick_install()) {
                    throw new RuntimeException('Installer failed: ' . $installer->error_message);
                }
            } catch (Throwable $e) {
                $this->dropDatabase(self::GOLDEN_DB);
                $this->dropDatabaseUser(self::GOLDEN_USER);
                $this->removeSiteDirectory(self::GOLDEN_SITE);
                $this->audit('golden.build.failed', 'golden');
                throw $e;
            }
            // Only the root account reads the golden database, so its own user and site folder go.
            $this->dropDatabaseUser(self::GOLDEN_USER);
            $this->removeSiteDirectory(self::GOLDEN_SITE);

            $version = $this->codeVersion();
            $golden = [
                'db_name' => self::GOLDEN_DB,
                'version' => $version['label'],
                'database' => $version['database'],
                'built_at' => gmdate('c'),
            ];
            file_put_contents($this->stateDir . '/golden.state', json_encode($golden, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $this->audit('golden.build.ok', 'golden');
            return $golden + ['seconds' => round(microtime(true) - $started, 1)];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{label: string, database: int} */
    private function codeVersion(): array
    {
        $root = $this->root;
        return (function () use ($root): array {
            require $root . '/version.php';
            /** @var string $v_major */
            /** @var string $v_minor */
            /** @var string $v_patch */
            /** @var int|string $v_database */
            return ['label' => "$v_major.$v_minor.$v_patch", 'database' => (int) $v_database];
        })();
    }

    /**
     * The golden database must exist and match the code version exactly, or practices cloned from it
     * would start out behind (or ahead of) the code they run on.
     *
     * @return array<string, mixed>
     */
    private function assertGoldenCurrent(): array
    {
        $file = $this->stateDir . '/golden.state';
        $golden = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($golden) || ($golden['db_name'] ?? '') !== self::GOLDEN_DB) {
            throw new RuntimeException('No golden database yet: run "build-golden" first');
        }
        $version = $this->codeVersion();
        if (($golden['version'] ?? '') !== $version['label'] || (int) ($golden['database'] ?? 0) !== $version['database']) {
            throw new RuntimeException(
                "The golden database is for {$golden['version']} but the code is {$version['label']}: run \"build-golden\" again"
            );
        }
        $link = $this->db->connect();
        $stmt = $link->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $name = self::GOLDEN_DB;
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $link->close();
        if (!$exists) {
            throw new RuntimeException('The golden database is missing: run "build-golden" again');
        }
        return $golden;
    }

    /**
     * Create the practice's database, user and site folder by copying the golden database, then give
     * the copy this practice's identity. Replaces the installer for a new practice.
     */
    private function cloneFromGolden(PracticeSpec $spec, string $dbPass, string $adminPass): void
    {
        $source = self::GOLDEN_DB;
        $link = $this->db->connect();
        $link->query('SET SESSION foreign_key_checks = 0');
        // Copy rows exactly as they are, including zero dates and ids of 0 that some seed rows use.
        $link->query("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");

        [$charset, $collation] = $link->query(
            "SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$source'"
        )->fetch_row() ?? throw new RuntimeException('Golden database not found');
        if (!preg_match('/^[a-z0-9_]+$/', (string) $charset) || !preg_match('/^[a-z0-9_]+$/', (string) $collation)) {
            throw new RuntimeException('Unexpected character set on the golden database');
        }
        $link->query("CREATE DATABASE `{$spec->dbName}` CHARACTER SET $charset COLLATE $collation");
        $host = $link->real_escape_string($this->db->loginHost);
        $link->query("CREATE USER '{$spec->dbUser}'@'$host' IDENTIFIED BY '" . $link->real_escape_string($dbPass) . "'");
        $link->query("GRANT ALL PRIVILEGES ON `{$spec->dbName}`.* TO '{$spec->dbUser}'@'$host'");

        $tables = [];
        $result = $link->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$source' AND TABLE_TYPE = 'BASE TABLE'"
        );
        while ($row = $result->fetch_row()) {
            if (!preg_match('/^[A-Za-z0-9_$]+$/', (string) $row[0])) {
                throw new RuntimeException('Unexpected table name in the golden database');
            }
            $tables[] = (string) $row[0];
        }
        foreach ($tables as $table) {
            $link->query("CREATE TABLE `{$spec->dbName}`.`$table` LIKE `$source`.`$table`");
            $link->query("INSERT INTO `{$spec->dbName}`.`$table` SELECT * FROM `$source`.`$table`");
        }
        // A copy that silently lost rows would be worse than a failure, so compare every table.
        foreach ($tables as $table) {
            $expected = $link->query("SELECT COUNT(*) FROM `$source`.`$table`")->fetch_row()[0] ?? null;
            $actual = $link->query("SELECT COUNT(*) FROM `{$spec->dbName}`.`$table`")->fetch_row()[0] ?? null;
            if ($expected !== $actual) {
                throw new RuntimeException("Clone check failed: table $table has $actual rows, expected $expected");
            }
        }
        $link->select_db($spec->dbName);
        $this->personalizeClone($link, $spec, $adminPass);
        $link->close();

        $this->createSiteDirectory($spec, $dbPass);
    }

    /** Replace the golden database's placeholder admin and name with this practice's. */
    private function personalizeClone(mysqli $link, PracticeSpec $spec, string $adminPass): void
    {
        $hash = password_hash($adminPass, PASSWORD_DEFAULT);
        $placeholder = self::GOLDEN_ADMIN;
        $oldGroup = self::GOLDEN_GROUP;
        $statements = [
            ['UPDATE users SET username = ?, fname = ?, lname = ? WHERE username = ?', 'ssss',
                [$spec->adminUser, $spec->adminFirst, $spec->adminLast, $placeholder]],
            ['UPDATE users_secure SET username = ?, password = ?, last_update_password = NOW() WHERE username = ?', 'sss',
                [$spec->adminUser, $hash, $placeholder]],
            ['UPDATE `groups` SET name = ?, user = ? WHERE user = ? AND name = ?', 'ssss',
                [$spec->name, $spec->adminUser, $placeholder, $oldGroup]],
            ['UPDATE gacl_aro SET value = ?, name = ? WHERE value = ?', 'sss',
                [$spec->adminUser, $spec->adminLast, $placeholder]],
        ];
        foreach ($statements as [$sql, $types, $params]) {
            $stmt = $link->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            if ($stmt->affected_rows < 1) {
                throw new RuntimeException('Clone personalisation matched nothing for: ' . $sql);
            }
        }
        $stmt = $link->prepare(
            'INSERT INTO globals (gl_name, gl_index, gl_value) VALUES (?, 0, ?) ON DUPLICATE KEY UPDATE gl_value = VALUES(gl_value)'
        );
        foreach ($this->customGlobals($spec) as $name => $setting) {
            $value = $setting['value'];
            $stmt->bind_param('ss', $name, $value);
            $stmt->execute();
        }
    }

    /** Copy the site template to sites/<domain>/ and write this practice's sqlconf.php, as the installer does. */
    private function createSiteDirectory(PracticeSpec $spec, string $dbPass): void
    {
        $src = "{$this->sitesDir}/{$this->templateSite}";
        $dst = "{$this->sitesDir}/{$spec->domain}";
        if (!mkdir($dst, 0775)) {
            throw new RuntimeException("Cannot create $dst");
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($items as $item) {
            $target = $dst . '/' . substr($item->getPathname(), strlen($src) + 1);
            if ($item->isDir()) {
                mkdir($target, 0775, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
        // The installer clears this folder so each practice makes its own encryption keys.
        foreach (glob($dst . '/documents/logs_and_misc/methods/*') ?: [] as $file) {
            unlink($file);
        }
        $conf = "<?php\n//  OpenEMR\n//  MySQL Config\n\n"
            . "\$host\t= '{$this->db->host}';\n\$port\t= '{$this->db->port}';\n"
            . "\$login\t= '{$spec->dbUser}';\n\$pass\t= '$dbPass';\n\$dbase\t= '{$spec->dbName}';\n\n"
            . "\$sqlconf = array();\nglobal \$sqlconf;\n\$sqlconf[\"host\"]= \$host;\n\$sqlconf[\"port\"] = \$port;\n"
            . "\$sqlconf[\"login\"] = \$login;\n\$sqlconf[\"pass\"] = \$pass;\n\$sqlconf[\"dbase\"] = \$dbase;\n\n"
            . "//////////////////////////\n//////////////////////////\n//////////////////////////\n"
            . "//////DO NOT TOUCH THIS///\n\$config = 1; /////////////\n"
            . "//////////////////////////\n//////////////////////////\n//////////////////////////\n?>\n";
        if (file_put_contents($dst . '/sqlconf.php', $conf) === false) {
            throw new RuntimeException('Cannot write sqlconf.php');
        }
    }

    /** @return array<string, string> */
    private function templateGlobals(PracticeSpec $spec): array
    {
        if ($spec->specialty === '') {
            return [];
        }
        $file = "{$this->templatesDir}/{$spec->specialty}/globals.json";
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException("$file must contain a JSON object");
        }
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $out[$key] = (string) $value;
            }
        }
        return $out;
    }

    /**
     * Run templates/<specialty>/seed.sql in the new practice's database: custom forms, lists and
     * other data a specialty starts with. The file comes from the repository (not from the spec) and
     * runs as the practice's own database user, so it can only ever touch that practice.
     */
    private function applySeed(PracticeSpec $spec, string $dbPass): void
    {
        if ($spec->specialty === '') {
            return;
        }
        $file = "{$this->templatesDir}/{$spec->specialty}/seed.sql";
        if (!is_file($file)) {
            return;
        }
        $link = $this->db->connect($spec->dbUser, $dbPass, $spec->dbName);
        // multi_query stops at the first failing statement, and strict mode raises it when we move on.
        $link->multi_query((string) file_get_contents($file));
        do {
            $result = $link->store_result();
            if ($result instanceof mysqli_result) {
                $result->free();
            }
        } while ($link->more_results() && $link->next_result());
        $link->close();
    }

    /** Copy templates/<specialty>/site/** over the new site directory (form layouts, logos, ...). */
    private function applyOverlay(PracticeSpec $spec): void
    {
        if ($spec->specialty === '') {
            return;
        }
        $src = "{$this->templatesDir}/{$spec->specialty}/site";
        if (!is_dir($src)) {
            $this->logger->info('No overlay for specialty {specialty}', ['specialty' => $spec->specialty]);
            return;
        }
        $dst = "{$this->sitesDir}/{$spec->domain}";
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($items as $item) {
            $rel = substr($item->getPathname(), strlen($src) + 1);
            if ($rel === 'sqlconf.php') {
                throw new RuntimeException('A specialty overlay must not contain sqlconf.php');
            }
            $target = $dst . '/' . $rel;
            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0775, true)) {
                    throw new RuntimeException("Cannot create $target");
                }
            } elseif (!copy($item->getPathname(), $target)) {
                throw new RuntimeException("Cannot copy overlay file to $target");
            }
        }
    }

    /**
     * Register, install and enable the requested modules in this practice only, using the same
     * console command (and module listener hooks) as the Module Manager.
     */
    private function enableModules(PracticeSpec $spec): void
    {
        if ($spec->modules === []) {
            return;
        }
        $this->console($spec, ['--modaction=discover']);
        foreach ($spec->modules as $module) {
            foreach (['install', 'enable'] as $action) {
                $output = $this->console($spec, ["--modaction=$action", "--modname=$module"]);
                // The command exits 0 even when the action reports a failure, so read its status line.
                if (!preg_match('/^Success$/mi', $output) && !str_contains($output, 'Handled by the module itself')) {
                    throw new RuntimeException("Module $module: $action did not report success");
                }
            }
        }
    }

    /**
     * @param list<string> $args
     * @return string combined output
     */
    private function console(PracticeSpec $spec, array $args): string
    {
        $cmd = [PHP_BINARY, $this->root . '/bin/console', 'openemr:zfc-module', '--site=' . $spec->domain, ...$args];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
        if (!is_resource($proc)) {
            throw new RuntimeException('Cannot start the module console command');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $this->logger->info('Module command {args}: {out}', ['args' => implode(' ', $args), 'out' => trim($stdout)]);
        if ($code !== 0) {
            throw new RuntimeException('Module command failed (' . implode(' ', $args) . '): ' . trim($stdout . $stderr));
        }
        return $stdout;
    }

    /**
     * Make the admin's first login open OpenEMR's own "Password Reset" tab, by dating the password as
     * if it were set a full expiry period ago. The prompt is built in and needs no core change, but
     * it is a prompt, not a lock: the admin can dismiss it until the grace period ends.
     * Returns false when the practice has password expiry switched off, so nothing can be prompted.
     */
    private function armPasswordChange(PracticeSpec $spec, string $dbPass): bool
    {
        if (!$spec->requirePasswordChange) {
            return false;
        }
        $link = $this->db->connect($spec->dbUser, $dbPass, $spec->dbName);
        $armed = $this->backdatePassword($link, $spec->adminUser);
        $link->close();
        if (!$armed) {
            $this->logger->warning('Password expiry is off for {domain}: no first-login prompt', ['domain' => $spec->domain]);
        }
        return $armed;
    }

    private function backdatePassword(mysqli $link, string $username): bool
    {
        $row = $link->query("SELECT gl_value FROM globals WHERE gl_name = 'password_expiration_days' AND gl_index = 0")->fetch_row();
        $days = (int) ($row[0] ?? 0);
        if ($days <= 0) {
            return false;
        }
        $stmt = $link->prepare('UPDATE users_secure SET last_update_password = DATE_SUB(NOW(), INTERVAL ? DAY) WHERE username = ?');
        $stmt->bind_param('is', $days, $username);
        $stmt->execute();
        return $stmt->affected_rows === 1;
    }

    /**
     * Give a practice's admin a new random password (for a lost or never-delivered one) and re-arm the
     * first-login prompt. Works on the database directly, using the same hashing as the installer.
     *
     * @return array<string, mixed>
     */
    public function resetAdmin(string $domain, ?string $username, ?string $secretsFile): array
    {
        $lock = $this->lock($domain);
        try {
            $state = $this->readState($domain);
            if ($state === null || ($state['status'] ?? '') !== 'active') {
                throw new RuntimeException("$domain is not an active provisioned practice");
            }
            $username ??= is_string($state['admin_user'] ?? null) ? $state['admin_user'] : null;
            if ($username === null || !preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
                throw new InvalidArgumentException('This practice has no recorded admin: pass --username=');
            }
            $secretsHandle = self::reserveSecretsFile($secretsFile);
            try {
                $password = self::secret(20);
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $link = $this->db->connect(null, null, (string) $state['db_name']);
                // Clearing the history and the failed-login counters means the new password is accepted
                // and an account locked out by earlier attempts can sign in again.
                $stmt = $link->prepare(
                    'UPDATE users_secure SET password = ?, last_update_password = NOW(), password_history1 = NULL,'
                    . ' password_history2 = NULL, password_history3 = NULL, password_history4 = NULL,'
                    . ' login_fail_counter = 0, last_login_fail = NULL WHERE username = ?'
                );
                $stmt->bind_param('ss', $hash, $username);
                $stmt->execute();
                if ($stmt->affected_rows !== 1) {
                    throw new RuntimeException("No user $username in $domain");
                }
                $armed = $this->backdatePassword($link, $username);
                $link->close();
            } catch (Throwable $e) {
                self::discardSecretsFile($secretsHandle, $secretsFile);
                throw $e;
            }
            $this->audit('reset-admin', $domain);
            return [
                'domain' => $domain,
                'admin' => self::deliverSecret($username, $password, $secretsHandle, $secretsFile),
                'first_login' => $armed
                    ? 'The admin is asked to change the password on first login'
                    : 'Password expiry is off for this practice: no first-login prompt',
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Create the secrets file (new, owner-only) BEFORE anything is changed, so a bad path can never
     * leave a password changed in the database but not delivered anywhere.
     *
     * @return resource|null
     */
    private static function reserveSecretsFile(?string $file)
    {
        if ($file === null) {
            return null;
        }
        $old = umask(0177);
        $handle = @fopen($file, 'x');
        umask($old);
        if ($handle === false) {
            throw new RuntimeException("Cannot create $file (its folder must exist and the file must not)");
        }
        return $handle;
    }

    /** @param resource|null $handle */
    private static function discardSecretsFile($handle, ?string $file): void
    {
        if ($handle !== null && $file !== null) {
            fclose($handle);
            @unlink($file);
        }
    }

    /**
     * Print the secret in the result, or, with a reserved file, write it there and leave it out of
     * the output so it does not end up in logs or a terminal history.
     *
     * @param resource|null $handle
     * @return array<string, string>
     */
    private static function deliverSecret(string $username, string $password, $handle, ?string $file): array
    {
        if ($handle === null || $file === null) {
            return ['username' => $username, 'initial_password' => $password];
        }
        fwrite($handle, json_encode(['username' => $username, 'initial_password' => $password], JSON_THROW_ON_ERROR) . "\n");
        fclose($handle);
        return ['username' => $username, 'initial_password_file' => $file];
    }

    private function setAdminProfile(PracticeSpec $spec, string $dbPass): void
    {
        if ($spec->adminEmail === '') {
            return;
        }
        $link = $this->db->connect($spec->dbUser, $dbPass, $spec->dbName);
        $stmt = $link->prepare('UPDATE users SET email = ? WHERE username = ?');
        // bind_param needs variables (it binds by reference), not readonly properties.
        $email = $spec->adminEmail;
        $username = $spec->adminUser;
        $stmt->bind_param('ss', $email, $username);
        $stmt->execute();
        $link->close();
    }

    private function verifyDatabase(PracticeSpec $spec, string $dbPass): void
    {
        $link = $this->db->connect($spec->dbUser, $dbPass, $spec->dbName);
        $checks = [
            'admin user exists' => ['SELECT COUNT(*) FROM users WHERE username = ?', 1],
            'admin has a password' => ['SELECT COUNT(*) FROM users_secure WHERE username = ?', 1],
            'admin is in an access-control group' => [
                'SELECT COUNT(*) FROM gacl_aro a JOIN gacl_groups_aro_map m ON m.aro_id = a.id WHERE a.value = ?',
                1,
            ],
        ];
        $username = $spec->adminUser;
        foreach ($checks as $label => [$sql, $min]) {
            $stmt = $link->prepare($sql);
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $count = (int) ($stmt->get_result()->fetch_row()[0] ?? 0);
            if ($count < $min) {
                throw new RuntimeException("Verification failed: $label");
            }
        }
        foreach ($spec->modules as $module) {
            $stmt = $link->prepare('SELECT COUNT(*) FROM modules WHERE mod_directory = ? AND mod_active = 1');
            $stmt->bind_param('s', $module);
            $stmt->execute();
            if ((int) ($stmt->get_result()->fetch_row()[0] ?? 0) !== 1) {
                throw new RuntimeException("Verification failed: module $module is not active");
            }
        }
        $link->close();
        if (!is_file("{$this->sitesDir}/{$spec->domain}/sqlconf.php")) {
            throw new RuntimeException('Verification failed: sqlconf.php missing');
        }
    }

    /** Fetch the login page and sign in as the new admin, sending Host: <domain> to $baseUrl. */
    private function verifyLogin(string $baseUrl, PracticeSpec $spec, string $adminPass, bool $expectPrompt): void
    {
        $jar = tempnam(sys_get_temp_dir(), 'prov');
        $request = function (string $url, ?array $post = null) use ($jar, $spec): array {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_COOKIEJAR => $jar,
                CURLOPT_COOKIEFILE => $jar,
                CURLOPT_HTTPHEADER => ['Host: ' . $spec->domain],
            ]);
            if ($post !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
            }
            $raw = curl_exec($ch);
            if (!is_string($raw)) {
                throw new RuntimeException('Login check could not reach ' . $url);
            }
            $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            return [$status, substr($raw, 0, $headerSize), substr($raw, $headerSize)];
        };
        try {
            $base = rtrim($baseUrl, '/');
            [$status, , $body] = $request("$base/interface/login/login.php?site={$spec->domain}");
            // The page title carries the practice name, so look for the login form field instead.
            if ($status !== 200 || !str_contains($body, 'name="authUser"')) {
                throw new RuntimeException("Login page check failed (HTTP $status)");
            }
            [$status, $headers] = $request("$base/interface/main/main_screen.php?auth=login&site={$spec->domain}", [
                'authUser' => $spec->adminUser,
                'clearPass' => $adminPass,
                'languageChoice' => '1',
                'new_login_session_management' => '1',
            ]);
            if ($status !== 302 || !preg_match('/^Location:.*main\.php/mi', $headers)) {
                throw new RuntimeException("Admin login check failed (HTTP $status)");
            }
            // Open the page login sends the admin to and check whether the password-reset tab is there.
            preg_match('/^Location:\s*(\S+)/mi', $headers, $m);
            $target = (string) parse_url($m[1] ?? '', PHP_URL_PATH) . '?' . (string) parse_url($m[1] ?? '', PHP_URL_QUERY);
            [$status, , $body] = $request($base . $target);
            $hasPrompt = str_contains($body, 'pwd_expires_alert');
            if ($status !== 200 || $hasPrompt !== $expectPrompt) {
                throw new RuntimeException('First-login check failed: password prompt '
                    . ($hasPrompt ? 'shown' : 'missing') . ' but ' . ($expectPrompt ? 'expected' : 'not expected'));
            }
        } finally {
            @unlink($jar);
        }
    }

    /** Remove whatever this practice owns: database, database user, site directory. Safe to repeat. */
    private function rollback(PracticeSpec $spec): void
    {
        $this->dropDatabase($spec->dbName);
        $this->dropDatabaseUser($spec->dbUser);
        $this->removeSiteDirectory($spec->domain);
    }

    private function dropDatabase(string $dbName): void
    {
        $link = $this->db->connect();
        $link->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
        $link->close();
    }

    private function dropDatabaseUser(string $dbUser): void
    {
        $link = $this->db->connect();
        $host = $link->real_escape_string($this->db->loginHost);
        $link->query("DROP USER IF EXISTS '{$dbUser}'@'{$host}'");
        $link->close();
    }

    private function removeSiteDirectory(string $domain): void
    {
        $dir = "{$this->sitesDir}/{$domain}";
        $real = realpath($dir);
        // Only ever delete a direct child of sites/, and never the template or default site.
        if (
            $real !== false
            && dirname($real) === realpath($this->sitesDir)
            && !in_array(basename($real), ['default', $this->templateSite], true)
        ) {
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($real);
        }
    }

    /** Refuse to start on top of an unrecorded site, database or user: we would not own it. */
    private function assertNothingLeftBehind(PracticeSpec $spec): void
    {
        if (file_exists("{$this->sitesDir}/{$spec->domain}")) {
            throw new PracticeExistsException("sites/{$spec->domain} already exists and was not created by a failed run");
        }
        $template = "{$this->sitesDir}/{$this->templateSite}";
        if (!is_dir("$template/documents")) {
            throw new RuntimeException("Site template not found or incomplete: $template");
        }
        // A real site (default, or any practice) has sqlconf.php. Copying one would also copy its
        // documents and keys into the new practice, so only a template without it is accepted.
        if (file_exists("$template/sqlconf.php")) {
            throw new RuntimeException("Refusing to use $template as a template: it contains sqlconf.php, so it is a live site");
        }
        // Fail in seconds on a mistyped module name, not after the two-minute install.
        foreach ($spec->modules as $module) {
            $base = $this->root . '/interface/modules';
            if (!is_dir("$base/custom_modules/$module") && !is_dir("$base/zend_modules/module/$module")) {
                throw new InvalidArgumentException("Module $module not found under interface/modules");
            }
        }
        $link = $this->db->connect();
        $dbName = $spec->dbName;
        $dbUser = $spec->dbUser;
        $stmt = $link->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $stmt->bind_param('s', $dbName);
        $stmt->execute();
        $dbExists = $stmt->get_result()->num_rows > 0;
        $stmt = $link->prepare('SELECT 1 FROM mysql.user WHERE User = ?');
        $stmt->bind_param('s', $dbUser);
        $stmt->execute();
        $userExists = $stmt->get_result()->num_rows > 0;
        $link->close();
        if ($dbExists || $userExists) {
            throw new PracticeExistsException("Database {$spec->dbName} or user {$spec->dbUser} already exists");
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private function step(array &$state, string $name, callable $fn): void
    {
        $this->logger->info('Step {step}', ['step' => $name]);
        $started = microtime(true);
        $fn();
        $state['steps'][$name] = ['status' => 'ok', 'seconds' => round(microtime(true) - $started, 1)];
        $state['updated_at'] = gmdate('c');
        $this->saveState($state);
    }

    /** @return resource */
    private function lock(string $domain)
    {
        $handle = fopen($this->stateDir . '/' . $domain . '.lock', 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException("Another operation is running for $domain");
        }
        return $handle;
    }

    /** @return array<string, mixed>|null */
    private function readState(string $domain): ?array
    {
        $file = $this->stateDir . '/' . $domain . '.json';
        if (!is_file($file)) {
            return null;
        }
        $state = json_decode((string) file_get_contents($file), true);
        return is_array($state) ? $state : null;
    }

    /** @param array<string, mixed> $state */
    private function saveState(array $state): void
    {
        $file = $this->stateDir . '/' . $state['domain'] . '.json';
        $tmp = $file . '.tmp';
        file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        rename($tmp, $file);
    }

    private function audit(string $event, string $domain): void
    {
        $line = json_encode(['at' => gmdate('c'), 'event' => $event, 'domain' => $domain], JSON_THROW_ON_ERROR);
        file_put_contents($this->stateDir . '/audit.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }

    private static function secret(int $length): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $out;
    }
}

final class PracticeExistsException extends RuntimeException
{
}

// ---- command line entry point ----

/**
 * @param list<string> $argv
 * @return array{list<string>, array<string, string>}
 */
function parseArgs(array $argv): array
{
    $positional = [];
    $options = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $arg, $m)) {
            $options[$m[1]] = $m[2] ?? '1';
        } else {
            $positional[] = $arg;
        }
    }
    return [$positional, $options];
}

function fail(string $message, int $code): never
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}

[$args, $opts] = parseArgs($argv);
$command = $args[0] ?? '';

$rootPass = getenv('PROVISION_DB_ROOT_PASS') ?: (getenv('MYSQL_ROOT_PASS') ?: '');
$host = getenv('PROVISION_DB_HOST') ?: (getenv('MYSQL_HOST') ?: 'localhost');
if (!preg_match('/^[A-Za-z0-9._-]+$/', $host)) {
    fail('Invalid database host', 2);
}
$db = new DbServer(
    $host,
    (int) (getenv('PROVISION_DB_PORT') ?: 3306),
    getenv('PROVISION_DB_ROOT_USER') ?: 'root',
    $rootPass,
    getenv('PROVISION_DB_LOGIN_HOST') ?: '%',
);
$logger = new ConsoleLogger(new StreamOutput(fopen('php://stderr', 'w'), OutputInterface::VERBOSITY_VERBOSE));
$provisioner = new Provisioner(
    $openemrRoot,
    getenv('PROVISION_STATE_DIR') ?: $openemrRoot . '/sites/.provisioning',
    $db,
    // Relative to sites/ because the Installer resolves "source_site_id" that way.
    (static function (): string {
        $template = getenv('PROVISION_TEMPLATE_SITE') ?: '../contrib/util/provisioning/site-template';
        if (!preg_match('#^[A-Za-z0-9._/-]+$#', $template) || str_contains($template, '//')) {
            fail('Invalid PROVISION_TEMPLATE_SITE', 2);
        }
        return $template;
    })(),
    getenv('PROVISION_TEMPLATES_DIR') ?: __DIR__ . '/templates',
    $logger,
);

try {
    $result = match ($command) {
        'create' => (function () use ($provisioner, $opts): array {
            $file = $opts['spec'] ?? fail('create needs --spec=FILE', 2);
            $json = is_file($file) ? file_get_contents($file) : fail("Spec file not found: $file", 2);
            $data = json_decode((string) $json, true);
            if (!is_array($data)) {
                fail('Spec is not valid JSON', 2);
            }
            try {
                $spec = PracticeSpec::fromArray($data);
            } catch (InvalidArgumentException $e) {
                fail('Invalid spec: ' . $e->getMessage(), 2);
            }
            return $provisioner->create($spec, $opts['verify-url'] ?? null, $opts['secrets-file'] ?? null, isset($opts['clone']));
        })(),
        'status' => $provisioner->status($args[1] ?? fail('status needs a domain', 2))
            ?? fail('Unknown practice', 1),
        'list' => array_map(
            fn(array $s): array => array_intersect_key($s, array_flip(['domain', 'name', 'specialty', 'status', 'updated_at'])),
            $provisioner->all()
        ),
        'build-golden' => $provisioner->buildGolden(),
        'reset-admin' => $provisioner->resetAdmin(
            $args[1] ?? fail('reset-admin needs a domain', 2),
            $opts['username'] ?? null,
            $opts['secrets-file'] ?? null
        ),
        'destroy' => (function () use ($provisioner, $args, $opts): array {
            $domain = $args[1] ?? fail('destroy needs a domain', 2);
            if (($opts['confirm'] ?? '') !== $domain) {
                fail("Re-run with --confirm=$domain to permanently delete this practice's database and files", 2);
            }
            return $provisioner->destroy($domain);
        })(),
        default => fail("Usage: provision-practice.php create|build-golden|status|list|reset-admin|destroy (see header of this file)", 2),
    };
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} catch (PracticeExistsException $e) {
    fail($e->getMessage(), 3);
} catch (InvalidArgumentException $e) {
    fail('Invalid spec: ' . $e->getMessage(), 2);
} catch (Throwable $e) {
    $logger->error('Operation failed: {message}', ['message' => $e->getMessage()]);
    exit(1);
}
