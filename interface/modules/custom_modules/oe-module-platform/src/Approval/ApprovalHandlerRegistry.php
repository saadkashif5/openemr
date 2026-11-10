<?php

/**
 * The action types that can be proposed. Features register their handler here.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Platform\Approval;

final readonly class ApprovalHandlerRegistry
{
    /** @var array<string, ApprovalActionHandler> */
    private array $handlers;

    public function __construct(ApprovalActionHandler ...$handlers)
    {
        $byType = [];
        foreach ($handlers as $handler) {
            $byType[$handler->actionType()] = $handler;
        }
        $this->handlers = $byType;
    }

    public function get(string $actionType): ?ApprovalActionHandler
    {
        return $this->handlers[$actionType] ?? null;
    }
}
