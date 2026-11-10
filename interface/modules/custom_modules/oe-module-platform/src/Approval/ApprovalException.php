<?php

/**
 * An approval operation was refused. The message is generic on purpose; the reason is the error code.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Platform\Approval;

use RuntimeException;
use Throwable;

final class ApprovalException extends RuntimeException
{
    private function __construct(public readonly ApprovalError $error, ?Throwable $previous = null)
    {
        parent::__construct('Approval refused: ' . $error->value, 0, $previous);
    }

    public static function because(ApprovalError $error, ?Throwable $previous = null): self
    {
        return new self($error, $previous);
    }
}
