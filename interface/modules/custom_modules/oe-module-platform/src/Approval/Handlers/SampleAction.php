<?php

/**
 * A harmless built-in action that proves the approval flow end to end. It changes nothing: it returns what it
 * was given. Real actions (sign a note, place an order, message a patient) register their own handler.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Platform\Approval\Handlers;

use OpenEMR\Modules\Platform\Actor\ActorContext;
use OpenEMR\Modules\Platform\Approval\ApprovalActionHandler;

final class SampleAction implements ApprovalActionHandler
{
    public const TYPE = 'platform.sample';

    public function actionType(): string
    {
        return self::TYPE;
    }

    public function requiredAcl(): array
    {
        return ['patients', 'demo'];
    }

    public function execute(array $payload, ActorContext $approver): array
    {
        return ['echo' => $payload, 'executed_as' => $approver->username];
    }
}
