<?php

declare(strict_types=1);

namespace Platform\Dashboard;

use PDO;
use Symfony\Component\HttpFoundation\Request;

/** Operator login, session, CSRF tokens and role checks. */
final class Auth
{
    private const MAX_FAILED_LOGINS = 5;
    private const LOCK_MINUTES = 15;
    private const IDLE_SECONDS = 1800;
    private const ABSOLUTE_SECONDS = 43200;

    /** What each role may do. Viewers can look, operators can run routine work, owners can do everything. */
    private const PERMISSIONS = [
        'owner' => ['view', 'create', 'reset_admin', 'reveal', 'audit', 'destroy', 'build_golden', 'open'],
        'operator' => ['view', 'create', 'reset_admin', 'reveal', 'audit', 'open'],
        'viewer' => ['view'],
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Audit $audit,
        private readonly bool $secureCookies = false,
    ) {
    }

    public function startSession(Request $request): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('platform_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $request->getBaseUrl() === '' ? '/' : dirname($request->getBaseUrl()),
            // Behind a load balancer that ends HTTPS, Apache sees plain HTTP: the setting covers that.
            'secure' => $this->secureCookies || $request->isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /** Returns an error message, or null when the operator is now signed in. */
    public function attempt(string $email, string $password, ?string $ip): ?string
    {
        $stmt = $this->pdo->prepare('SELECT * FROM operators WHERE email = ?');
        $stmt->execute([strtolower(trim($email))]);
        $operator = $stmt->fetch();

        if ($operator === false) {
            // Spend about the same time as a real check so the response does not reveal which emails exist.
            password_hash($password, PASSWORD_DEFAULT);
            $this->audit->log(null, 'login.failed', $email, ['reason' => 'unknown email'], $ip);
            return 'Invalid email or password.';
        }
        if (!$operator['active']) {
            $this->audit->log($operator, 'login.failed', $email, ['reason' => 'inactive'], $ip);
            return 'Invalid email or password.';
        }
        if ($operator['locked_until'] !== null && strtotime($operator['locked_until'] . ' UTC') > time()) {
            $this->audit->log($operator, 'login.blocked', $email, [], $ip);
            return 'Too many failed attempts. Try again in ' . self::LOCK_MINUTES . ' minutes.';
        }
        if (!password_verify($password, $operator['password_hash'])) {
            $fails = (int) $operator['failed_logins'] + 1;
            $lock = $fails >= self::MAX_FAILED_LOGINS;
            $this->pdo->prepare(
                'UPDATE operators SET failed_logins = ?, locked_until = ' . ($lock ? 'DATE_ADD(NOW(), INTERVAL ' . self::LOCK_MINUTES . ' MINUTE)' : 'NULL') . ' WHERE id = ?'
            )->execute([$lock ? 0 : $fails, $operator['id']]);
            $this->audit->log($operator, $lock ? 'login.locked' : 'login.failed', $email, ['reason' => 'bad password'], $ip);
            return 'Invalid email or password.';
        }

        $this->pdo->prepare('UPDATE operators SET failed_logins = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?')
            ->execute([$operator['id']]);
        session_regenerate_id(true);
        $_SESSION = ['operator_id' => (int) $operator['id'], 'started' => time(), 'active' => time()];
        $this->audit->log($operator, 'login.ok', $email, [], $ip);
        return null;
    }

    /** @return array<string, mixed>|null */
    public function current(): ?array
    {
        $id = $_SESSION['operator_id'] ?? null;
        if (!is_int($id)) {
            return null;
        }
        $now = time();
        if ($now - (int) ($_SESSION['active'] ?? 0) > self::IDLE_SECONDS || $now - (int) ($_SESSION['started'] ?? 0) > self::ABSOLUTE_SECONDS) {
            $this->logout();
            return null;
        }
        $_SESSION['active'] = $now;
        $stmt = $this->pdo->prepare('SELECT id, email, name, role, must_change_password FROM operators WHERE id = ? AND active = 1');
        $stmt->execute([$id]);
        $operator = $stmt->fetch();
        if ($operator === false) {
            $this->logout();
            return null;
        }
        return $operator;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function csrfToken(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public function checkCsrf(?string $token): bool
    {
        return is_string($token) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
    }

    /** @param array<string, mixed> $operator */
    public static function can(array $operator, string $permission): bool
    {
        return in_array($permission, self::PERMISSIONS[$operator['role']] ?? [], true);
    }

    /** Returns an error message, or null after the password was changed. */
    public function changePassword(int $operatorId, string $current, string $new, string $confirm): ?string
    {
        $stmt = $this->pdo->prepare('SELECT password_hash FROM operators WHERE id = ?');
        $stmt->execute([$operatorId]);
        $hash = $stmt->fetchColumn();
        if (!is_string($hash) || !password_verify($current, $hash)) {
            return 'The current password is not correct.';
        }
        if ($new !== $confirm) {
            return 'The new passwords do not match.';
        }
        if (strlen($new) < 12) {
            return 'Use at least 12 characters.';
        }
        if ($new === $current) {
            return 'Choose a password you have not used for this account just now.';
        }
        $this->pdo->prepare('UPDATE operators SET password_hash = ?, must_change_password = 0 WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $operatorId]);
        return null;
    }
}
