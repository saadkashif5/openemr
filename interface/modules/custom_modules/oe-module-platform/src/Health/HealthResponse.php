<?php

/**
 * Status code and JSON body of the health endpoint.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Platform\Health;

final readonly class HealthResponse
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public int $status,
        public array $body,
    ) {
    }
}
