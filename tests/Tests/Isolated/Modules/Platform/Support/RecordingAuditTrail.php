<?php

/**
 * Collects audit entries so tests can check them.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\Platform\Support;

use OpenEMR\Modules\Platform\Actor\ActorContext;
use OpenEMR\Modules\Platform\Audit\AuditTrail;

final class RecordingAuditTrail implements AuditTrail
{
    /** @var list<array{event: string, actor: string, correlation: string, patient: ?int, details: string, success: bool}> */
    public array $entries = [];

    public function record(string $event, ActorContext $actor, string $correlationId, ?int $patientId, string $details, bool $success = true): void
    {
        $this->entries[] = [
            'event' => $event,
            'actor' => $actor->label(),
            'correlation' => $correlationId,
            'patient' => $patientId,
            'details' => $details,
            'success' => $success,
        ];
    }

    /** @return list<string> */
    public function events(): array
    {
        return array_column($this->entries, 'event');
    }
}
