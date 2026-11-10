<?php

/**
 * Where platform events are recorded. The real one writes to the practice's own OpenEMR log.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Platform\Audit;

use OpenEMR\Modules\Platform\Actor\ActorContext;

interface AuditTrail
{
    /**
     * @param string $event for example "approval.proposed"
     * @param string $correlationId ties this entry to the others of the same approval or request
     */
    public function record(string $event, ActorContext $actor, string $correlationId, ?int $patientId, string $details, bool $success = true): void;
}
