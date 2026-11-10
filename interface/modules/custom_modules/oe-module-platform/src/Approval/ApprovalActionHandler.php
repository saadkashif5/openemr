<?php

/**
 * One kind of sensitive action. The agent proposes it; after a person approves, execute() runs once, under the
 * approver's identity and permissions. The payload it receives is byte for byte what was proposed.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Platform\Approval;

use OpenEMR\Modules\Platform\Actor\ActorContext;

interface ApprovalActionHandler
{
    /** Stable name, for example "note.sign". */
    public function actionType(): string;

    /**
     * The OpenEMR ACL a person needs in order to approve this action, as [section, value].
     *
     * @return array{0: string, 1: string}
     */
    public function requiredAcl(): array;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed> stored as the result of the approval
     */
    public function execute(array $payload, ActorContext $approver): array;
}
