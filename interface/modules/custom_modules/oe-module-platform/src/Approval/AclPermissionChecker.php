<?php

/**
 * OpenEMR's own access control (phpGACL), checked for the named person, not for whoever is in the session.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Platform\Approval;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Modules\Platform\Actor\ActorContext;

final class AclPermissionChecker implements PermissionChecker
{
    public function allows(ActorContext $actor, string $section, string $value): bool
    {
        return AclMain::aclCheckCore($section, $value, $actor->username);
    }
}
