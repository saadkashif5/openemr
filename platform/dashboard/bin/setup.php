<?php

/**
 * One-time setup of the super-admin dashboard. Safe to run again: it only adds what is missing.
 *
 *   php platform/dashboard/bin/setup.php
 *
 * Creates the oe_platform database and its own restricted user on the shared database server, loads
 * schema.sql, and writes var/config.php (owner-only). Needs the database root password in
 * PROVISION_DB_ROOT_PASS or MYSQL_ROOT_PASS, and must run as the web server user.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "Refusing to run as root: run as the web server user so the files are owned correctly\n");
    exit(2);
}

$appDir = dirname(__DIR__);
$host = getenv('PROVISION_DB_HOST') ?: (getenv('MYSQL_HOST') ?: 'localhost');
$port = (int) (getenv('PROVISION_DB_PORT') ?: 3306);
$rootUser = getenv('PROVISION_DB_ROOT_USER') ?: 'root';
$rootPass = getenv('PROVISION_DB_ROOT_PASS') ?: (getenv('MYSQL_ROOT_PASS') ?: '');
$loginHost = getenv('PROVISION_DB_LOGIN_HOST') ?: '%';
if (!preg_match('/^[A-Za-z0-9._-]+$/', $host) || !preg_match('/^[A-Za-z0-9._%-]+$/', $loginHost)) {
    fwrite(STDERR, "Invalid database host\n");
    exit(2);
}

foreach (['var', 'var/secrets', 'var/tmp'] as $dir) {
    if (!is_dir("$appDir/$dir") && !mkdir("$appDir/$dir", 0700, true)) {
        fwrite(STDERR, "Cannot create $dir\n");
        exit(1);
    }
}

$configFile = "$appDir/var/config.php";
if (is_file($configFile)) {
    $existing = require $configFile;
    $db = $existing['db'];
    echo "Existing configuration found; only checking the schema.\n";
} else {
    $db = ['host' => $host, 'port' => $port, 'name' => 'oe_platform', 'user' => 'u_platform', 'pass' => bin2hex(random_bytes(16))];
    $root = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $rootUser, $rootPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $root->exec('CREATE DATABASE IF NOT EXISTS `oe_platform` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    $quotedHost = $root->quote($loginHost);
    $root->exec("CREATE USER IF NOT EXISTS 'u_platform'@$quotedHost IDENTIFIED BY " . $root->quote($db['pass']));
    // The user may exist from an earlier attempt that never wrote its config, so always set the password.
    $root->exec("ALTER USER 'u_platform'@$quotedHost IDENTIFIED BY " . $root->quote($db['pass']));
    $root->exec("GRANT ALL PRIVILEGES ON `oe_platform`.* TO 'u_platform'@$quotedHost");

    $old = umask(0177);
    $written = file_put_contents(
        $configFile,
        "<?php\n\nreturn " . var_export(['db' => $db, 'verify_url' => getenv('DASHBOARD_VERIFY_URL') ?: 'http://localhost'], true) . ";\n"
    );
    umask($old);
    if ($written === false) {
        fwrite(STDERR, "Cannot write var/config.php\n");
        exit(1);
    }
    echo "Created database oe_platform, user u_platform and var/config.php.\n";
}

$pdo = new PDO(
    "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",
    $db['user'],
    $db['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec((string) file_get_contents("$appDir/schema.sql"));

// Upgrades for installs that already have the tables (CREATE TABLE IF NOT EXISTS leaves them as they were).
// Safe to repeat. MODIFY just restates the list of job types; the column is added only when it is missing.
$pdo->exec("ALTER TABLE jobs MODIFY type ENUM('create', 'reset_admin', 'destroy', 'build_golden', 'open_practice', 'close_practice') NOT NULL");
$hasRunAfter = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'jobs' AND column_name = 'run_after'");
$hasRunAfter->execute();
if ((int) $hasRunAfter->fetchColumn() === 0) {
    $pdo->exec('ALTER TABLE jobs ADD COLUMN run_after DATETIME NULL AFTER created_at');
}
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo 'Schema ready: ' . implode(', ', $tables) . "\n";
echo "Next: php platform/dashboard/bin/create-operator.php EMAIL \"Full Name\" owner\n";
