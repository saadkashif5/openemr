<?php

/**
 * Why an approval operation was refused.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Platform\Approval;

enum ApprovalError: string
{
    case NotFound = 'not_found';
    case NotPending = 'not_pending';
    case Expired = 'expired';
    case Forbidden = 'forbidden';
    case AgentCannotDecide = 'agent_cannot_decide';
    case UnknownAction = 'unknown_action';
    case ExecutionFailed = 'execution_failed';

    /** The HTTP status the endpoint answers with. */
    public function httpStatus(): int
    {
        return match ($this) {
            self::NotFound => 404,
            self::NotPending, self::Expired => 409,
            self::Forbidden, self::AgentCannotDecide => 403,
            self::UnknownAction => 400,
            self::ExecutionFailed => 500,
        };
    }
}
