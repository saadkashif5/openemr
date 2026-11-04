<?php

declare(strict_types=1);

namespace Platform\Dashboard;

use InvalidArgumentException;
use PracticeSpec;
use RuntimeException;
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
                && is_file((string) $job['secret_file']) && Auth::can($operator, 'reveal'),
        ], 200, $operator);
    }

    /** Show the initial admin password once, then delete it: it is not kept anywhere else. */
    private function reveal(Request $request, array $operator, int $id): Response
    {
        $this->require($operator, 'reveal');
        $job = $this->jobs->find($id);
        $file = $job['secret_file'] ?? null;
        $inside = is_string($file) && realpath($file) !== false
            && str_starts_with(realpath($file), (realpath($this->config->secretsDir) ?: '') . DIRECTORY_SEPARATOR);
        if ($job === null || !$inside || $job['secret_revealed_at'] !== null || $job['status'] !== 'succeeded') {
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
        foreach (['create', 'reset_admin', 'reveal', 'audit', 'destroy', 'build_golden'] as $p) {
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
        $response->headers->add([
            'Content-Security-Policy' => "default-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'",
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
