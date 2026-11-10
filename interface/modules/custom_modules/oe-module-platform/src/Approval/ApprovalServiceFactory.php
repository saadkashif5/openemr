<?php

/**
 * Wires the approval service with the real database, OpenEMR's access control and audit log, and the registered handlers.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Platform\Approval;

use DateInterval;
use OpenEMR\BC\ServiceContainer;
use OpenEMR\Modules\Platform\Approval\Handlers\SampleAction;
use OpenEMR\Modules\Platform\Audit\OpenEmrAuditTrail;
use OpenEMR\Modules\Platform\Settings\SettingsRepository;

final class ApprovalServiceFactory
{
    public static function create(): ApprovalService
    {
        $minutes = (new SettingsRepository())->getInt(SettingsRepository::APPROVAL_TTL_MINUTES);
        return new ApprovalService(
            new DatabaseApprovalRepository(),
            new ApprovalHandlerRegistry(new SampleAction()),
            new AclPermissionChecker(),
            new OpenEmrAuditTrail(),
            ServiceContainer::getClock(),
            new DateInterval('PT' . max(1, $minutes) . 'M'),
        );
    }
}
