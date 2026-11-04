<?php

/**
 * Front controller for the super-admin dashboard.
 * Served at <host>/platform/dashboard/public/index.php/<page>.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Platform\Dashboard\App;
use Platform\Dashboard\Audit;
use Platform\Dashboard\Auth;
use Platform\Dashboard\Config;
use Platform\Dashboard\Database;
use Platform\Dashboard\Jobs;
use Platform\Dashboard\Practices;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

try {
    $config = Config::load();
    $pdo = Database::connect($config);
    $audit = new Audit($pdo);
    $app = new App($config, new Auth($pdo, $audit, $config->secureCookies), $audit, new Jobs($pdo), new Practices($config));
    $response = $app->handle(Request::createFromGlobals());
} catch (Throwable $e) {
    // Details go to the server log; the person sees nothing that could reveal how the system is built.
    error_log('Dashboard error: ' . $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    $response = new Response('The dashboard hit an error. Try again, or check the server log.', 500, [
        'Content-Type' => 'text/plain; charset=utf-8',
        'Cache-Control' => 'no-store',
    ]);
}
$response->send();
