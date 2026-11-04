<?php

/**
 * Loads OpenEMR's Composer autoloader (Twig, Symfony HttpFoundation), this app's classes and the
 * shared practice validation rules.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/vendor/autoload.php';
require_once $root . '/contrib/util/provisioning/PracticeSpec.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Platform\\Dashboard\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});
