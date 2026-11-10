<?php

/**
 * Asks whether a person holds an OpenEMR permission. Behind an interface so the rules can be tested without a database.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Platform\Approval;

use OpenEMR\Modules\Platform\Actor\ActorContext;

interface PermissionChecker
{
    public function allows(ActorContext $actor, string $section, string $value): bool;
}
