<?php

/**
 * Builds the ActorContext for a request: a person from the browser session, or an agent from an OAuth
 * (REST or FHIR) request, where the token names the client and the user it was issued for.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Platform\Actor;

use OpenEMR\Common\Http\HttpRestRequest;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class ActorResolver
{
    /** Null when nobody is signed in. */
    public function fromSession(SessionInterface $session, string $correlationId): ?ActorContext
    {
        $userId = $session->get('authUserID');
        $username = $session->get('authUser');
        if (!is_int($userId) && !(is_string($userId) && ctype_digit($userId))) {
            return null;
        }
        if ((int) $userId <= 0 || !is_string($username) || $username === '') {
            return null;
        }
        return ActorContext::human((int) $userId, $username, $correlationId);
    }

    /** Null when the token has no client or no user (for example a patient or a backend token). */
    public function fromRestRequest(HttpRestRequest $request, string $correlationId): ?ActorContext
    {
        $clientId = $request->getClientId();
        $userId = $request->getRequestUserId();
        $username = $request->getRequestUser()['username'] ?? null;
        if ($clientId === null || $clientId === '' || $userId === null || $userId <= 0 || !is_string($username) || $username === '') {
            return null;
        }
        return ActorContext::agent($clientId, $userId, $username, $correlationId);
    }
}
