<?php

/**
 * Writes platform events to OpenEMR's own audit log (the practice's `log` table, with its checksum), so they appear
 * next to every other event of that practice. Each entry carries the actor and the correlation id in its comment.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Platform\Audit;

use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\Platform\Actor\ActorContext;

final class OpenEmrAuditTrail implements AuditTrail
{
    public const LOG_EVENT = 'platform';

    public function record(string $event, ActorContext $actor, string $correlationId, ?int $patientId, string $details, bool $success = true): void
    {
        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        $provider = $session->get('authProvider');
        $comment = sprintf('%s corr=%s actor=%s %s', $event, $correlationId, $actor->label(), $details);
        EventAuditLogger::getInstance()->newEvent(
            self::LOG_EVENT,
            $actor->username,
            is_string($provider) ? $provider : '',
            $success ? 1 : 0,
            trim($comment),
            $patientId,
            'open-emr',
            'platform'
        );
    }
}
