<?php

declare(strict_types=1);

namespace Platform\Dashboard;

use InvalidArgumentException;
use PracticeSpec;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/** Routes, permission checks and pages of the super-admin dashboard. */
final class App
{
    private readonly Environment $twig;
    private string $base = '';

    public function __construct(
        private readonly Config $config,
        private readonly Auth $auth,
        private readonly Audit $audit,
        private readonly Jobs $jobs,
        private readonly Practices $practices,
    ) {
        $this->twig = new Environment(new FilesystemLoader($config->appDir . '/templates'), [
            'autoescape' => 'html',
            'strict_variables' => true,
            'cache' => false,
        ]);
    }

    public function handle(Request $request): Response
    {
        $this->base = $request->getBaseUrl();
        $this->auth->startSession($request);
        try {
            return $this->secure($this->dispatch($request));
        } catch (HttpError $e) {
            return $this->secure($this->page('error.twig', ['title' => $e->getMessage()], $e->status));
        }
    }

    private function dispatch(Request $request): Response
    {
        $path = rtrim($request->getPathInfo(), '/') ?: '/';
        $method = $request->getMethod();

        if ($method === 'POST' && !$this->auth->checkCsrf($request->request->getString('_csrf'))) {
            throw new HttpError(400, 'Your session expired or the form was invalid. Go back and try again.');
        }
        if ($path === '/login') {
            return $this->login($request);
        }
        $operator = $this->auth->current();
        if ($operator === null) {
            return $this->redirect('/login');
        }
        if ($operator['must_change_password'] && $path !== '/account/password' && $path !== '/logout') {
            return $this->redirect('/account/password');
        }

        if ($method === 'POST' && $path === '/logout') {
            $this->audit->log($operator, 'logout', $operator['email'], [], $request->getClientIp());
            $this->auth->logout();
            return $this->redirect('/login');
        }
        if ($path === '/account/password') {
            return $this->accountPassword($request, $operator);
        }
        if ($path === '/') {
            return $this->redirect('/practices');
        }
        if ($method === 'GET' && $path === '/practices') {
            return $this->practiceList($operator);
        }
        if ($path === '/practices/new') {
            $this->require($operator, 'create');
            return $this->newPractice($request, $operator);
        }
        if ($method === 'POST' && $path === '/practices') {
            $this->require($operator, 'create');
            return $this->createPractice($request, $operator);
        }
        if (preg_match('#^/practices/([^/]+)$#', $path, $m) && $method === 'GET') {
            return $this->practiceDetail($operator, $m[1]);
        }
        if (preg_match('#^/practices/([^/]+)/(reset-admin|destroy)$#', $path, $m) && $method === 'POST') {
            return $m[2] === 'reset-admin'
                ? $this->resetAdmin($request, $operator, $m[1])
                : $this->destroyPractice($request, $operator, $m[1]);
        }
        if (preg_match('#^/jobs/(\d+)$#', $path, $m) && $method === 'GET') {
            return $this->jobDetail($operator, (int) $m[1]);
        }
        if (preg_match('#^/jobs/(\d+)/reveal$#', $path, $m) && $method === 'POST') {
            return $this->reveal($request, $operator, (int) $m[1]);
        }
        // One-click open of a practice (ADM-01).
        if ($method === 'POST' && $path === '/open') {
            return $this->openPractice($request, $operator);
        }
        if (preg_match('#^/jobs/(\d+)/open$#', $path, $m) && $method === 'GET') {
            return $this->openWaiting($operator, (int) $m[1]);
        }
        if (preg_match('#^/jobs/(\d+)/status$#', $path, $m) && $method === 'GET') {
            return $this->openStatus($operator, (int) $m[1]);
        }
        if (preg_match('#^/jobs/(\d+)/handoff$#', $path, $m) && $method === 'POST') {
            return $this->openHandoff($request, $operator, (int) $m[1]);
        }
        if ($method === 'POST' && $path === '/golden/build') {
            $this->require($operator, 'build_golden');
            return $this->buildGolden($request, $operator);
        }
        if ($method === 'GET' && $path === '/audit') {
            $this->require($operator, 'audit');
            return $this->page('audit.twig', ['entries' => $this->audit->recent()], 200, $operator);
        }
        throw new HttpError(404, 'Page not found');
    }

    private function login(Request $request): Response
    {
        if ($this->auth->current() !== null) {
            return $this->redirect('/practices');
        }
        $error = null;
        if ($request->isMethod('POST')) {
            $error = $this->auth->attempt(
                $request->request->getString('email'),
                $request->request->getString('password'),
                $request->getClientIp()
            );
            if ($error === null) {
                return $this->redirect('/practices');
            }
        }
        return $this->page('login.twig', ['error' => $error], $error === null ? 200 : 401);
    }

    /** @param array<string, mixed> $operator */
    private function accountPassword(Request $request, array $operator): Response
    {
        $error = null;
        if ($request->isMethod('POST')) {
            $error = $this->auth->changePassword(
                (int) $operator['id'],
                $request->request->getString('current'),
                $request->request->getString('new'),
                $request->request->getString('confirm')
            );
            if ($error === null) {
                $this->audit->log($operator, 'password.changed', $operator['email'], [], $request->getClientIp());
                $this->flash('Your password was changed.');
                return $this->redirect('/practices');
            }
        }
        return $this->page('password.twig', ['error' => $error], $error === null ? 200 : 422, $operator);
    }

    /** @param array<string, mixed> $operator */
    private function practiceList(array $operator): Response
    {
        $practices = $this->practices->all();
        $known = array_column($practices, 'domain');
        // A practice that was just requested has no record yet, so show it from its queued job.
        foreach ($this->jobs->active() as $job) {
            if ($job['type'] === 'create' && !in_array($job['domain'], $known, true)) {
                $payload = json_decode((string) $job['payload'], true);
                array_unshift($practices, [
                    'domain' => $job['domain'],
                    'name' => $payload['spec']['name'] ?? '',
                    'specialty' => $payload['spec']['specialty'] ?? '',
                    'status' => $job['status'],
                    'created_at' => $job['created_at'],
                ]);
            }
        }
        return $this->page('practices.twig', [
            'practices' => $practices,
            'golden' => $this->practices->golden(),
            'jobs' => $this->jobs->recent(),
        ], 200, $operator);
    }

    /**
     * @param array<string, mixed> $operator
     * @param array<string, mixed> $form
     */
    private function newPractice(Request $request, array $operator, ?string $error = null, array $form = []): Response
    {
        return $this->page('practice_new.twig', [
            'error' => $error,
            'form' => $form + ['name' => '', 'domain' => '', 'specialty' => '', 'admin_username' => '', 'admin_first' => '',
                'admin_last' => '', 'admin_email' => '', 'modules' => [], 'clone' => false, 'require_password_change' => true],
            'specialties' => $this->practices->specialties(),
            'modules' => $this->practices->modules(),
            'golden' => $this->practices->golden(),
        ], $error === null ? 200 : 422, $operator);
    }

    /** @param array<string, mixed> $operator */
    private function createPractice(Request $request, array $operator): Response
    {
        $in = $request->request;
        $modules = array_values(array_intersect($in->all('modules'), $this->practices->modules()));
        $form = [
            'name' => $in->getString('name'),
            'domain' => strtolower(trim($in->getString('domain'))),
            'specialty' => $in->getString('specialty'),
            'admin_username' => trim($in->getString('admin_username')),
            'admin_first' => $in->getString('admin_first'),
            'admin_last' => $in->getString('admin_last'),
            'admin_email' => $in->getString('admin_email'),
            'modules' => $modules,
            'clone' => $in->getBoolean('clone'),
            'require_password_change' => $in->getBoolean('require_password_change'),
        ];
        $data = [
            'domain' => $form['domain'],
            'name' => $form['name'],
            'specialty' => $form['specialty'],
            'admin' => [
                'username' => $form['admin_username'],
                'first_name' => $form['admin_first'],
                'last_name' => $form['admin_last'],
                'email' => $form['admin_email'],
                'require_password_change' => $form['require_password_change'],
            ],
            'modules' => $modules,
        ];
        try {
            PracticeSpec::fromArray($data);
            if ($form['specialty'] !== '' && !in_array($form['specialty'], $this->practices->specialties(), true)) {
                throw new InvalidArgumentException('Choose one of the listed specialties');
            }
            $existing = $this->practices->find($form['domain']);
            if (($existing['status'] ?? '') === 'active' || file_exists($this->config->root . '/sites/' . $form['domain'])) {
                throw new InvalidArgumentException('A practice with that domain already exists');
            }
            if ($form['clone'] && $this->practices->golden()['status'] !== 'current') {
                throw new InvalidArgumentException('The golden database is not current: build it first, or untick "Create from the golden database"');
            }
            $jobId = $this->jobs->enqueue('create', $form['domain'], ['spec' => $data, 'clone' => $form['clone']], (int) $operator['id']);
        } catch (InvalidArgumentException | RuntimeException $e) {
            // These messages are written for the person filling in the form.
            return $this->newPractice($request, $operator, $e->getMessage(), $form);
        }
        $this->audit->log($operator, 'practice.create.requested', $form['domain'], [
            'specialty' => $form['specialty'], 'modules' => $modules, 'clone' => $form['clone'], 'job' => $jobId,
        ], $request->getClientIp());
        return $this->redirect('/jobs/' . $jobId);
    }

    /** @param array<string, mixed> $operator */
    private function practiceDetail(array $operator, string $domain): Response
    {
        $state = $this->practices->find($domain);
        if ($state === null) {
            throw new HttpError(404, 'Practice not found');
        }
        return $this->page('practice.twig', [
            'p' => $state,
            'jobs' => $this->jobs->forDomain($domain),
        ], 200, $operator);
    }

    /** @param array<string, mixed> $operator */
    private function resetAdmin(Request $request, array $operator, string $domain): Response
    {
        $this->require($operator, 'reset_admin');
        $state = $this->practices->find($domain);
        if ($state === null || ($state['status'] ?? '') !== 'active') {
            throw new HttpError(404, 'Practice not found or not active');
        }
        $payload = [];
        $username = trim($request->request->getString('username'));
        if (!isset($state['admin_user']) && preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            $payload['username'] = $username;
        }
        return $this->queue($request, $operator, 'reset_admin', $domain, $payload, 'practice.reset_admin.requested');
    }

    /** @param array<string, mixed> $operator */
    private function destroyPractice(Request $request, array $operator, string $domain): Response
    {
        $this->require($operator, 'destroy');
        if ($this->practices->find($domain) === null) {
            throw new HttpError(404, 'Practice not found');
        }
        if ($request->request->getString('confirm') !== $domain) {
            $this->flash('Nothing was deleted: the confirmation did not match the domain.', true);
            return $this->redirect('/practices/' . $domain);
        }
        return $this->queue($request, $operator, 'destroy', $domain, [], 'practice.destroy.requested');
    }

    /** @param array<string, mixed> $operator */
    private function buildGolden(Request $request, array $operator): Response
    {
        return $this->queue($request, $operator, 'build_golden', null, [], 'golden.build.requested');
    }

    /**
     * @param array<string, mixed> $operator
     * @param array<string, mixed> $payload
     */
    private function queue(Request $request, array $operator, string $type, ?string $domain, array $payload, string $auditAction): Response
    {
        try {
            $jobId = $this->jobs->enqueue($type, $domain, $payload, (int) $operator['id']);
        } catch (RuntimeException $e) {
            $this->flash($e->getMessage(), true);
            return $this->redirect($domain !== null ? '/practices/' . $domain : '/practices');
        }
        $this->audit->log($operator, $auditAction, $domain, ['job' => $jobId], $request->getClientIp());
        return $this->redirect('/jobs/' . $jobId);
    }

    /** @param array<string, mixed> $operator */
    private function jobDetail(array $operator, int $id): Response
    {
        $job = $this->jobs->find($id);
        if ($job === null) {
            throw new HttpError(404, 'Job not found');
        }
        $active = in_array($job['status'], ['queued', 'running'], true);
        $state = $job['domain'] !== null && $job['type'] === 'create' ? $this->practices->find((string) $job['domain']) : null;
        return $this->page('job.twig', [
            'job' => $job,
            'active' => $active,
            'steps' => $state['steps'] ?? [],
            'result' => json_decode((string) $job['result'], true),
            'canReveal' => $job['status'] === 'succeeded' && $job['secret_file'] !== null && $job['secret_revealed_at'] === null
                && $job['type'] !== 'open_practice' && is_file((string) $job['secret_file']) && Auth::can($operator, 'reveal'),
        ], 200, $operator);
    }

    /**
     * One click on "Open" (the form posts into a new tab). Queue the job that gives the practice's platform account a fresh
     * password, and send this tab to the page that waits for it and then signs in. Only for practices on the platform domain.
     *
     * @param array<string, mixed> $operator
     */
    private function openPractice(Request $request, array $operator): Response
    {
        $this->require($operator, 'open');
        $domain = trim($request->request->getString('domain'));
        $state = Practices::validDomain($domain) ? $this->practices->find($domain) : null;
        if ($state === null || ($state['status'] ?? '') !== 'active') {
            throw new HttpError(404, 'Practice not found or not active');
        }
        // Three different reasons it can be unavailable, each with its own message (they have different fixes).
        $platformDomain = $this->config->platformDomain;
        if ($platformDomain === null) {
            throw new HttpError(422, 'One-click open is not set up: add "platform_domain" (the domain your admin and practice addresses share, for example emb.example.com) to platform/dashboard/var/config.php.');
        }
        $host = $request->getHost();
        if ($host !== 'admin.' . $platformDomain && !str_ends_with($host, '.' . $platformDomain)) {
            throw new HttpError(422, 'Open the dashboard at admin.' . $platformDomain . ' to use one-click open. You are on ' . $host . ', which is a different site from the practices, so the practice would not accept the sign-in.');
        }
        if (!$this->config->isOnPlatformDomain($domain)) {
            throw new HttpError(422, $domain . ' is not under ' . $platformDomain . ' (for example a practice on its own domain). One-click open works for practices at <name>.' . $platformDomain . '; its own domain needs the signed-token sign-in, which is not built yet.');
        }
        try {
            $jobId = $this->jobs->enqueue('open_practice', $domain, [], (int) $operator['id']);
        } catch (RuntimeException $e) {
            throw new HttpError(409, $e->getMessage());
        }
        $this->audit->log($operator, 'practice.open.requested', $domain, ['job' => $jobId], $request->getClientIp());
        return $this->redirect('/jobs/' . $jobId . '/open');
    }

    /**
     * An open job this operator asked for, or a 404. Anyone else's, or any other kind of job, is "not found".
     *
     * @param array<string, mixed> $operator
     * @return array<string, mixed>
     */
    private function ownOpenJob(array $operator, int $id): array
    {
        $job = $this->jobs->find($id);
        if ($job === null || $job['type'] !== 'open_practice' || (int) $job['requested_by'] !== (int) $operator['id']) {
            throw new HttpError(404, 'Not found');
        }
        $this->require($operator, 'open');
        return $job;
    }

    /** @param array<string, mixed> $operator */
    private function openWaiting(array $operator, int $id): Response
    {
        $job = $this->ownOpenJob($operator, $id);
        $origin = $this->config->practiceOrigin((string) $job['domain']);
        $response = $this->page('open.twig', ['job' => $job, 'origin' => $origin], 200, $operator);
        // This one page may send a form to this practice's origin (and nowhere else); the script comes from our own file.
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; script-src 'self'; connect-src 'self'; form-action 'self' $origin; frame-ancestors 'none'; base-uri 'none'"
        );
        return $response;
    }

    /** @param array<string, mixed> $operator */
    private function openStatus(array $operator, int $id): Response
    {
        $job = $this->ownOpenJob($operator, $id);
        // Status only. The reason a job failed stays on the job page for operators; nothing secret is ever in here.
        return new JsonResponse(['status' => $job['status']], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Give the browser the one-time sign-in for the practice. Claimed atomically (a second call gets nothing), the secret
     * file is deleted as it is read, the open is audited, and the password is rotated again shortly after.
     *
     * @param array<string, mixed> $operator
     */
    private function openHandoff(Request $request, array $operator, int $id): Response
    {
        $job = $this->ownOpenJob($operator, $id);
        $domain = (string) $job['domain'];
        $file = $job['secret_file'] ?? null;
        $inside = is_string($file) && realpath($file) !== false
            && str_starts_with(realpath($file), (realpath($this->config->secretsDir) ?: '') . DIRECTORY_SEPARATOR);
        $age = $this->jobs->secondsSinceFinished($id);
        if ($job['status'] !== 'succeeded' || !$inside || $job['secret_revealed_at'] !== null || $age === null || $age > Jobs::CLOSE_IF_UNCLAIMED_SECONDS) {
            return new JsonResponse(['status' => 'unavailable'], 410, ['Cache-Control' => 'no-store']);
        }
        // Claim it first: if two requests arrive together, only one wins.
        if (!$this->jobs->markRevealed($id, (int) $operator['id'])) {
            return new JsonResponse(['status' => 'unavailable'], 410, ['Cache-Control' => 'no-store']);
        }
        $secret = json_decode((string) file_get_contents($file), true);
        @unlink($file);
        if (!is_array($secret) || !isset($secret['username'], $secret['initial_password'])) {
            return new JsonResponse(['status' => 'unavailable'], 410, ['Cache-Control' => 'no-store']);
        }
        // The used password stops working shortly: bring a waiting rotation forward unless another open is still pending.
        $this->jobs->scheduleClose($domain, Jobs::CLOSE_AFTER_HANDOFF_SECONDS, (int) $operator['id'], !$this->jobs->hasOtherPendingOpen($domain, $id));
        $this->audit->log($operator, 'practice.open', $domain, ['job' => $id, 'as' => $secret['username']], $request->getClientIp());
        return new JsonResponse([
            'status' => 'ok',
            'action' => $this->config->practiceOrigin($domain) . '/interface/main/main_screen.php?auth=login&site=' . rawurlencode($domain),
            'fields' => [
                'authUser' => $secret['username'],
                'clearPass' => $secret['initial_password'],
                'languageChoice' => '1',
                'new_login_session_management' => '1',
            ],
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /** Show the initial admin password once, then delete it: it is not kept anywhere else. */
    private function reveal(Request $request, array $operator, int $id): Response
    {
        $this->require($operator, 'reveal');
        $job = $this->jobs->find($id);
        $file = $job['secret_file'] ?? null;
        $inside = is_string($file) && realpath($file) !== false
            && str_starts_with(realpath($file), (realpath($this->config->secretsDir) ?: '') . DIRECTORY_SEPARATOR);
        // The platform account's password is never shown on a page: only the open handoff may use it.
        if ($job === null || !$inside || ($job['type'] ?? '') === 'open_practice' || $job['secret_revealed_at'] !== null || $job['status'] !== 'succeeded') {
            $this->flash('That password is no longer available. Use "Reset admin password" to issue a new one.', true);
            return $this->redirect('/jobs/' . $id);
        }
        // Mark it revealed first: if two people click at once, only one wins.
        if (!$this->jobs->markRevealed($id, (int) $operator['id'])) {
            $this->flash('That password was already revealed.', true);
            return $this->redirect('/jobs/' . $id);
        }
        $secret = json_decode((string) file_get_contents($file), true);
        @unlink($file);
        $this->audit->log($operator, 'secret.revealed', (string) $job['domain'], ['job' => $id], $request->getClientIp());
        $response = $this->page('reveal.twig', [
            'domain' => $job['domain'],
            'username' => is_array($secret) ? ($secret['username'] ?? '') : '',
            'password' => is_array($secret) ? ($secret['initial_password'] ?? '') : '',
        ], 200, $operator);
        $response->headers->set('Cache-Control', 'no-store');
        return $response;
    }

    /** @param array<string, mixed> $operator */
    private function require(array $operator, string $permission): void
    {
        if (!Auth::can($operator, $permission)) {
            throw new HttpError(403, 'Your role does not allow that');
        }
    }

    /**
     * @param array<string, mixed> $vars
     * @param array<string, mixed>|null $operator
     */
    private function page(string $template, array $vars, int $status = 200, ?array $operator = null): Response
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        $perms = [];
        foreach (['create', 'reset_admin', 'reveal', 'audit', 'destroy', 'build_golden', 'open'] as $p) {
            $perms[$p] = $operator !== null && Auth::can($operator, $p);
        }
        $html = $this->twig->render($template, $vars + [
            'base' => $this->base,
            // On the admin host the dashboard is served from the site root, so the base is empty.
            'assets' => $this->base === '' ? '' : dirname($this->base),
            'operator' => $operator,
            'perms' => $perms,
            'csrf' => $_SESSION['csrf'] ?? $this->auth->csrfToken(),
            'flash' => $flash,
        ]);
        return new Response($html, $status);
    }

    private function flash(string $message, bool $isError = false): void
    {
        $_SESSION['flash'] = ['message' => $message, 'error' => $isError];
    }

    private function redirect(string $path): RedirectResponse
    {
        return new RedirectResponse($this->base . $path);
    }

    /** Headers for every response: no framing, no inline script, nothing cached. */
    private function secure(Response $response): Response
    {
        // A page may set its own, narrower-purpose policy (the open handoff page does); every other page gets this one.
        if (!$response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', "default-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
        }
        $response->headers->add([
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
        ]);
        if (!$response->headers->has('Cache-Control') || $response->headers->get('Cache-Control') === 'no-cache, private') {
            $response->headers->set('Cache-Control', 'no-store');
        }
        return $response;
    }
}
