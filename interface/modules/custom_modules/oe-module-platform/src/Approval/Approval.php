<?php

/**
 * One approval request as stored: what is proposed, by whom, and what happened to it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Platform\Approval;

use DateTimeImmutable;
use OpenEMR\Modules\Platform\Actor\ActorType;

final readonly class Approval
{
    /**
     * @param array<string, mixed> $payload exactly what will be executed
     * @param array<string, mixed>|null $result
     */
    public function __construct(
        public int $id,
        public string $correlationId,
        public ApprovalStatus $status,
        public ActorType $proposedByType,
        public int $proposedByUserId,
        public string $proposedByClientId,
        public string $actionType,
        public string $resource,
        public ?int $patientId,
        public array $payload,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public ?int $decidedByUserId = null,
        public ?DateTimeImmutable $decidedAt = null,
        public ?DateTimeImmutable $executedAt = null,
        public ?array $result = null,
    ) {
    }
}
