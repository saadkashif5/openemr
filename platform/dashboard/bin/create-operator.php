<?php

/**
 * Create a dashboard login for someone on the company team.
 *
 *   php platform/dashboard/bin/create-operator.php EMAIL "Full Name" owner|operator|viewer [--secrets-file=PATH]
 *
 * A random password is generated and printed once (or written to a new owner-only file). The person
 * must change it at first login.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Platform\Dashboard\Audit;
use Platform\Dashboard\Config;
use Platform\Dashboard\Database;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$positional = [];
$file = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--secrets-file=')) {
        $file = substr($arg, 15);
    } else {
        $positional[] = $arg;
    }
}
[$email, $name, $role] = $positional + [null, null, null];
$email = is_string($email) ? strtolower(trim($email)) : '';
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || !is_string($name) || trim($name) === '' || !in_array($role, ['owner', 'operator', 'viewer'], true)) {
    fwrite(STDERR, "Usage: create-operator.php EMAIL \"Full Name\" owner|operator|viewer [--secrets-file=PATH]\n");
    exit(2);
}

$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
$password = '';
for ($i = 0; $i < 20; $i++) {
    $password .= $chars[random_int(0, strlen($chars) - 1)];
}

$handle = null;
if ($file !== null) {
    $old = umask(0177);
    $handle = @fopen($file, 'x');
    umask($old);
    if ($handle === false) {
        fwrite(STDERR, "Cannot create $file (its folder must exist and the file must not)\n");
        exit(1);
    }
}

$pdo = Database::connect(Config::load());
try {
    $pdo->prepare('INSERT INTO operators (email, name, password_hash, role) VALUES (?, ?, ?, ?)')
        ->execute([$email, trim($name), password_hash($password, PASSWORD_DEFAULT), $role]);
} catch (PDOException) {
    if ($handle !== null) {
        fclose($handle);
        @unlink((string) $file);
    }
    fwrite(STDERR, "Could not create $email: is there already a login with that email?\n");
    exit(1);
}
(new Audit($pdo))->log(null, 'operator.created', $email, ['role' => $role, 'by' => 'command line']);

if ($handle !== null) {
    fwrite($handle, json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR) . "\n");
    fclose($handle);
    echo "Created $email ($role). The password is in $file\n";
} else {
    echo "Created $email ($role).\nPassword (shown once): $password\n";
}
echo "They must change it at first login.\n";
