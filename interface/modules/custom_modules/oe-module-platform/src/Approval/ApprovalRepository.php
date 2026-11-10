<?php

/**
 * Storage for approvals. claim() is the only way a decision is made and it must be atomic: of several
 * simultaneous attempts on one approval exactly one wins, which is what makes an approval run once.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Platform\Approval;

use DateTimeImmutable;

interface ApprovalRepository
{
    public function insert(NewApproval $new): Approval;

    public function find(int $id): ?Approval;

    /** @return list<Approval> pending and not yet expired, oldest first */
    public function listPending(DateTimeImmutable $now): array;

    /**
     * Move a pending, unexpired approval to approved or rejected. False when it was not pending or had expired.
     */
    public function claim(int $id, ApprovalStatus $to, int $decidedByUserId, string $note, DateTimeImmutable $now): bool;

    /** @param array<string, mixed> $result */
    public function finish(int $id, ApprovalStatus $to, array $result, DateTimeImmutable $now): void;

    /** Mark pending approvals past their time as expired. Returns how many. */
    public function expireDue(DateTimeImmutable $now): int;
}
