<?php

/**
 * Who is acting: a person at the keyboard, or software (an agent or app) acting for a person.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Platform\Actor;

enum ActorType: string
{
    case Human = 'human';
    case Agent = 'agent';
}
