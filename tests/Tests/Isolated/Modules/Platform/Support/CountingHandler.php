<?php

/**
 * A handler that counts executions and remembers what it was given, and can be told to fail.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\Platform\Support;

use OpenEMR\Modules\Platform\Actor\ActorContext;
use OpenEMR\Modules\Platform\Approval\ApprovalActionHandler;

final class CountingHandler implements ApprovalActionHandler
{
    public int $runs = 0;
    /** @var array<string, mixed>|null */
    public ?array $lastPayload = null;
    public ?string $lastApprover = null;

    public function __construct(private readonly bool $fails = false)
    {
    }

    public function actionType(): string
    {
        return 'test.action';
    }

    public function requiredAcl(): array
    {
        return ['patients', 'med'];
    }

    public function execute(array $payload, ActorContext $approver): array
    {
        $this->runs++;
        $this->lastPayload = $payload;
        $this->lastApprover = $approver->username;
        if ($this->fails) {
            throw new \RuntimeException('internal detail that must not leak');
        }
        return ['done' => true];
    }
}
