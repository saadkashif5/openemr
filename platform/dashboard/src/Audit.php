<?php

declare(strict_types=1);

namespace Platform\Dashboard;

use PDO;

/** Append-only record of what each operator did. Never stores passwords or other secrets. */
final class Audit
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed>|null $operator
     * @param array<string, mixed> $detail
     */
    public function log(?array $operator, string $action, ?string $target = null, array $detail = [], ?string $ip = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_log (operator_id, operator_email, action, target, detail, ip) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $operator['id'] ?? null,
            $operator['email'] ?? null,
            $action,
            $target,
            $detail === [] ? null : json_encode($detail, JSON_THROW_ON_ERROR),
            $ip,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 200): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM audit_log ORDER BY id DESC LIMIT ' . (int) $limit);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
