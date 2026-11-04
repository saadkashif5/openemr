<?php

declare(strict_types=1);

namespace Platform\Dashboard;

use PDO;
use RuntimeException;

/**
 * The queue between the web pages and the worker. A page only ever records a job; the worker, which
 * alone holds the database root credential, runs it. Payloads never contain secrets.
 */
final class Jobs
{
    public const TYPES = ['create', 'reset_admin', 'destroy', 'build_golden'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $payload */
    public function enqueue(string $type, ?string $domain, array $payload, int $operatorId): int
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new RuntimeException('Unknown job type');
        }
        // One job at a time per practice (and one golden build): a double click must not run twice.
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM jobs WHERE status IN ('queued', 'running') AND type = ? AND (domain <=> ?)"
        );
        $stmt->execute([$type, $domain]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new RuntimeException('That is already queued or running.');
        }
        $this->pdo->prepare('INSERT INTO jobs (type, domain, payload, requested_by) VALUES (?, ?, ?, ?)')
            ->execute([$type, $domain, json_encode($payload, JSON_THROW_ON_ERROR), $operatorId]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT j.*, o.email AS requested_by_email FROM jobs j LEFT JOIN operators o ON o.id = j.requested_by WHERE j.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function forDomain(string $domain, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM jobs WHERE domain = ? ORDER BY id DESC LIMIT ' . (int) $limit);
        $stmt->execute([$domain]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function active(): array
    {
        return $this->pdo->query("SELECT * FROM jobs WHERE status IN ('queued', 'running') ORDER BY id")->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 15): array
    {
        return $this->pdo->query('SELECT * FROM jobs ORDER BY id DESC LIMIT ' . (int) $limit)->fetchAll();
    }

    /** Worker side: take the oldest queued job, or null. */
    public function claimNext(): ?array
    {
        $id = $this->pdo->query("SELECT id FROM jobs WHERE status = 'queued' ORDER BY id LIMIT 1")->fetchColumn();
        if ($id === false) {
            return null;
        }
        $claimed = $this->pdo->prepare("UPDATE jobs SET status = 'running', started_at = NOW() WHERE id = ? AND status = 'queued'");
        $claimed->execute([$id]);
        return $claimed->rowCount() === 1 ? $this->find((int) $id) : null;
    }

    /** @param array<string, mixed>|null $result */
    public function finish(int $id, bool $ok, int $exitCode, ?array $result, ?string $error, ?string $secretFile): void
    {
        $this->pdo->prepare(
            'UPDATE jobs SET status = ?, finished_at = NOW(), exit_code = ?, result = ?, error = ?, secret_file = ? WHERE id = ?'
        )->execute([
            $ok ? 'succeeded' : 'failed',
            $exitCode,
            $result === null ? null : json_encode($result, JSON_THROW_ON_ERROR),
            $error,
            $secretFile,
            $id,
        ]);
    }

    /** A worker that died mid-job leaves it "running" for ever: mark those failed when a worker starts. */
    public function failInterrupted(): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE jobs SET status = 'failed', finished_at = NOW(), error = 'Interrupted: the worker stopped while running this job. Check the practice state before retrying.' WHERE status = 'running'"
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function markRevealed(int $id, int $operatorId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE jobs SET secret_revealed_at = NOW(), secret_revealed_by = ? WHERE id = ? AND secret_revealed_at IS NULL'
        );
        $stmt->execute([$operatorId, $id]);
        return $stmt->rowCount() === 1;
    }
}
