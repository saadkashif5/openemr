<?php

/**
 * In-memory stand-in for the approvals table, with the same atomic claim rule.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\Platform\Support;

use DateTimeImmutable;
use OpenEMR\Modules\Platform\Approval\Approval;
use OpenEMR\Modules\Platform\Approval\ApprovalRepository;
use OpenEMR\Modules\Platform\Approval\ApprovalStatus;
use OpenEMR\Modules\Platform\Approval\NewApproval;

final class InMemoryApprovalRepository implements ApprovalRepository
{
    /** @var array<int, Approval> */
    private array $rows = [];
    private int $next = 1;

    public function insert(NewApproval $new): Approval
    {
        $approval = new Approval(
            $this->next,
            $new->correlationId,
            ApprovalStatus::Pending,
            $new->proposedByType,
            $new->proposedByUserId,
            $new->proposedByClientId,
            $new->actionType,
            $new->resource,
            $new->patientId,
            $new->payload,
            $new->createdAt,
            $new->expiresAt,
        );
        $this->rows[$this->next++] = $approval;
        return $approval;
    }

    public function find(int $id): ?Approval
    {
        return $this->rows[$id] ?? null;
    }

    public function listPending(DateTimeImmutable $now): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn(Approval $a): bool => $a->status === ApprovalStatus::Pending && $a->expiresAt > $now
        ));
    }

    public function claim(int $id, ApprovalStatus $to, int $decidedByUserId, string $note, DateTimeImmutable $now): bool
    {
        $row = $this->rows[$id] ?? null;
        if ($row === null || $row->status !== ApprovalStatus::Pending || $row->expiresAt <= $now) {
            return false;
        }
        $this->replace($row, $to, $decidedByUserId, $now, null, null);
        return true;
    }

    public function finish(int $id, ApprovalStatus $to, array $result, DateTimeImmutable $now): void
    {
        $row = $this->rows[$id];
        $this->replace($row, $to, $row->decidedByUserId, $row->decidedAt, $now, $result);
    }

    public function expireDue(DateTimeImmutable $now): int
    {
        $count = 0;
        foreach ($this->rows as $id => $row) {
            if ($row->status === ApprovalStatus::Pending && $row->expiresAt <= $now) {
                $this->replace($row, ApprovalStatus::Expired, null, null, null, null);
                $count++;
            }
        }
        return $count;
    }

    /** @param array<string, mixed>|null $result */
    private function replace(Approval $row, ApprovalStatus $status, ?int $decidedBy, ?DateTimeImmutable $decidedAt, ?DateTimeImmutable $executedAt, ?array $result): void
    {
        $this->rows[$row->id] = new Approval(
            $row->id,
            $row->correlationId,
            $status,
            $row->proposedByType,
            $row->proposedByUserId,
            $row->proposedByClientId,
            $row->actionType,
            $row->resource,
            $row->patientId,
            $row->payload,
            $row->createdAt,
            $row->expiresAt,
            $decidedBy,
            $decidedAt,
            $executedAt,
            $result,
        );
    }
}
