<?php

/**
 * Approvals in the practice's own database (table platform_approval).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Platform\Approval;

use DateTimeImmutable;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\Platform\Actor\ActorType;

final class DatabaseApprovalRepository implements ApprovalRepository
{
    private const FORMAT = 'Y-m-d H:i:s';

    public function insert(NewApproval $new): Approval
    {
        $id = QueryUtils::sqlInsert(
            'INSERT INTO platform_approval (correlation_id, status, proposed_by_type, proposed_by_user_id, proposed_by_client_id,'
            . ' action_type, resource, patient_id, payload, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $new->correlationId,
                ApprovalStatus::Pending->value,
                $new->proposedByType->value,
                $new->proposedByUserId,
                $new->proposedByClientId,
                $new->actionType,
                $new->resource,
                $new->patientId,
                json_encode($new->payload, JSON_THROW_ON_ERROR),
                $new->createdAt->format(self::FORMAT),
                $new->expiresAt->format(self::FORMAT),
            ]
        );
        return $this->find((int) $id) ?? throw new \RuntimeException('Approval was not stored');
    }

    public function find(int $id): ?Approval
    {
        $rows = QueryUtils::fetchRecords('SELECT * FROM platform_approval WHERE id = ?', [$id], true);
        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function listPending(DateTimeImmutable $now): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT * FROM platform_approval WHERE status = ? AND expires_at > ? ORDER BY id ASC',
            [ApprovalStatus::Pending->value, $now->format(self::FORMAT)],
            true
        );
        return array_map($this->hydrate(...), $rows);
    }

    public function claim(int $id, ApprovalStatus $to, int $decidedByUserId, string $note, DateTimeImmutable $now): bool
    {
        // One UPDATE decides, so of two simultaneous attempts only one changes a row. No SQL logging: it would
        // run extra statements and disturb the affected-row count this relies on.
        QueryUtils::sqlStatementThrowException(
            'UPDATE platform_approval SET status = ?, decided_by_user_id = ?, decided_at = ?, decision_note = ?'
            . ' WHERE id = ? AND status = ? AND expires_at > ?',
            [$to->value, $decidedByUserId, $now->format(self::FORMAT), substr($note, 0, 255), $id, ApprovalStatus::Pending->value, $now->format(self::FORMAT)],
            true
        );
        return QueryUtils::affectedRows() === 1;
    }

    public function finish(int $id, ApprovalStatus $to, array $result, DateTimeImmutable $now): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE platform_approval SET status = ?, executed_at = ?, result = ? WHERE id = ? AND status = ?',
            [$to->value, $now->format(self::FORMAT), json_encode($result, JSON_THROW_ON_ERROR), $id, ApprovalStatus::Approved->value],
            true
        );
    }

    public function expireDue(DateTimeImmutable $now): int
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE platform_approval SET status = ? WHERE status = ? AND expires_at <= ?',
            [ApprovalStatus::Expired->value, ApprovalStatus::Pending->value, $now->format(self::FORMAT)],
            true
        );
        return (int) QueryUtils::affectedRows();
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Approval
    {
        $result = isset($row['result']) && is_string($row['result']) && $row['result'] !== ''
            ? json_decode($row['result'], true, 512, JSON_THROW_ON_ERROR)
            : null;
        return new Approval(
            (int) $row['id'],
            (string) $row['correlation_id'],
            ApprovalStatus::from((string) $row['status']),
            ActorType::from((string) $row['proposed_by_type']),
            (int) $row['proposed_by_user_id'],
            (string) $row['proposed_by_client_id'],
            (string) $row['action_type'],
            (string) $row['resource'],
            $row['patient_id'] === null ? null : (int) $row['patient_id'],
            json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR),
            new DateTimeImmutable((string) $row['created_at']),
            new DateTimeImmutable((string) $row['expires_at']),
            $row['decided_by_user_id'] === null ? null : (int) $row['decided_by_user_id'],
            $row['decided_at'] === null ? null : new DateTimeImmutable((string) $row['decided_at']),
            $row['executed_at'] === null ? null : new DateTimeImmutable((string) $row['executed_at']),
            is_array($result) ? $result : null,
        );
    }
}
