<?php

/**
 * Runs the jobs that dashboard pages queue (create, reset admin, destroy, build golden database).
 *
 *   php platform/dashboard/bin/worker.php          # keep running, polling for jobs
 *   php platform/dashboard/bin/worker.php --once   # run what is queued, then stop
 *
 * This is the only part of the dashboard that needs the database root password (PROVISION_DB_ROOT_PASS
 * or MYSQL_ROOT_PASS in its environment) and write access to sites/. Run it as the web server user,
 * and in production as a separate task from the web pages. The web pages never run provisioning.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Platform\Dashboard\Audit;
use Platform\Dashboard\Config;
use Platform\Dashboard\Database;
use Platform\Dashboard\Jobs;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "Refusing to run as root: run as the web server user\n");
    exit(2);
}

const JOB_TIMEOUT_SECONDS = 1800;
const SECRET_KEEP_SECONDS = 86400;

$once = in_array('--once', $argv, true);
$config = Config::load();
$pdo = Database::connect($config);
$jobs = new Jobs($pdo);
$audit = new Audit($pdo);
$script = $config->root . '/contrib/util/provisioning/provision-practice.php';

foreach ([$config->secretsDir, $config->tmpDir] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
        fwrite(STDERR, "Cannot create $dir\n");
        exit(1);
    }
}

function logLine(string $message): void
{
    fwrite(STDERR, '[' . gmdate('H:i:s') . '] ' . $message . "\n");
}

/**
 * @param list<string> $args
 * @return array{int, string, string} exit code, stdout, stderr
 */
function run(array $args, string $cwd): array
{
    $env = getenv();
    $env['OPENEMR_ENABLE_PROVISIONING'] = '1';
    $proc = proc_open([PHP_BINARY, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
    if (!is_resource($proc)) {
        return [1, '', 'Could not start the provisioning script'];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = $err = '';
    $deadline = time() + JOB_TIMEOUT_SECONDS;
    while (true) {
        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        $status = proc_get_status($proc);
        if (!$status['running']) {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            break;
        }
        if (time() > $deadline) {
            proc_terminate($proc);
            $err .= "\nStopped: the job ran longer than " . JOB_TIMEOUT_SECONDS . ' seconds';
            break;
        }
        usleep(200000);
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return [$status['running'] ? 1 : ($status['exitcode'] >= 0 ? $status['exitcode'] : $code), $out, $err];
}

/** @param array<string, mixed> $job */
function commandFor(array $job, string $script, Config $config, string $specFile, string $secretFile): array
{
    $payload = json_decode((string) $job['payload'], true) ?: [];
    $domain = (string) $job['domain'];
    return match ($job['type']) {
        'create' => array_values(array_filter([
            $script, 'create', "--spec=$specFile", "--secrets-file=$secretFile", '--verify-url=' . $config->verifyUrl,
            !empty($payload['clone']) ? '--clone' : null,
        ])),
        'reset_admin' => array_values(array_filter([
            $script, 'reset-admin', $domain, "--secrets-file=$secretFile",
            isset($payload['username']) ? '--username=' . $payload['username'] : null,
        ])),
        'destroy' => [$script, 'destroy', $domain, "--confirm=$domain"],
        'build_golden' => [$script, 'build-golden'],
    };
}

/** Delete revealed-never secrets after a day: a password nobody collected should not sit on disk. */
function sweepSecrets(Config $config): void
{
    foreach (glob($config->secretsDir . '/*.json') ?: [] as $file) {
        if (filemtime($file) < time() - SECRET_KEEP_SECONDS) {
            @unlink($file);
        }
    }
}

$interrupted = $jobs->failInterrupted();
if ($interrupted > 0) {
    logLine("Marked $interrupted interrupted job(s) as failed");
}
logLine('Worker started' . ($once ? ' (once)' : ''));

while (true) {
    sweepSecrets($config);
    $job = $jobs->claimNext();
    if ($job === null) {
        if ($once) {
            break;
        }
        sleep(2);
        continue;
    }

    $id = (int) $job['id'];
    logLine("Job $id: {$job['type']} {$job['domain']}");
    $specFile = $config->tmpDir . "/job-$id.spec.json";
    $secretFile = $config->secretsDir . "/job-$id.json";
    $payload = json_decode((string) $job['payload'], true) ?: [];
    if ($job['type'] === 'create') {
        $old = umask(0177);
        file_put_contents($specFile, json_encode($payload['spec'] ?? [], JSON_THROW_ON_ERROR));
        umask($old);
    }

    try {
        [$code, $stdout, $stderr] = run(commandFor($job, $script, $config, $specFile, $secretFile), dirname($script));
    } catch (Throwable $e) {
        error_log('Worker error on job ' . $id . ': ' . $e->getMessage());
        [$code, $stdout, $stderr] = [1, '', 'The worker could not run this job. See the server log.'];
    }
    @unlink($specFile);

    $result = json_decode($stdout, true);
    $ok = $code === 0;
    // The script's error lines are written for operators and never contain secrets. Keep the tail.
    $error = $ok ? null : mb_substr(trim($stderr) !== '' ? trim($stderr) : "The script exited with code $code", -1500);
    $hasSecret = is_file($secretFile);
    $jobs->finish($id, $ok, $code, is_array($result) ? $result : null, $error, $hasSecret ? $secretFile : null);
    $audit->log(null, 'job.' . ($ok ? 'succeeded' : 'failed'), (string) ($job['domain'] ?? ''), ['job' => $id, 'type' => $job['type'], 'exit' => $code]);
    logLine("Job $id " . ($ok ? 'succeeded' : "failed (exit $code)"));
}
