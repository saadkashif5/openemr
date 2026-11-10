<?php

/**
 * What is needed to open an approval request.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Platform\Approval;

use DateTimeImmutable;
use OpenEMR\Modules\Platform\Actor\ActorType;

final readonly class NewApproval
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $correlationId,
        public ActorType $proposedByType,
        public int $proposedByUserId,
        public string $proposedByClientId,
        public string $actionType,
        public string $resource,
        public ?int $patientId,
        public array $payload,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
