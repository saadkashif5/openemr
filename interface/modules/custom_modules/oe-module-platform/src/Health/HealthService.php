<?php

/**
 * Describes the signed-in user of the current session for the health endpoint. A signed-out session
 * gets 401, not a redirect to the login page, because callers are scripts and the shell, not people.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Platform\Health;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

final readonly class HealthService
{
    public const MODULE = 'oe-module-platform';

    public function __construct(private string $moduleVersion)
    {
    }

    public function describe(SessionInterface $session): HealthResponse
    {
        $userId = $session->get('authUserID');
        $username = $session->get('authUser');
        $site = $session->get('site_id');

        $base = [
            'module' => self::MODULE,
            'version' => $this->moduleVersion,
            'site' => is_string($site) ? $site : null,
        ];

        if (!is_int($userId) && !(is_string($userId) && ctype_digit($userId))) {
            return new HealthResponse(401, ['status' => 'unauthenticated', 'authenticated' => false] + $base);
        }
        if ((int) $userId <= 0 || !is_string($username) || $username === '') {
            return new HealthResponse(401, ['status' => 'unauthenticated', 'authenticated' => false] + $base);
        }

        return new HealthResponse(200, [
            'status' => 'ok',
            'authenticated' => true,
            'user' => ['id' => (int) $userId, 'username' => $username],
        ] + $base);
    }
}
