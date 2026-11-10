<?php

/**
 * The platform module health endpoint reports the session user, or 401 when signed out.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\Platform;

use OpenEMR\Modules\Platform\Health\HealthService;
use OpenEMR\Tests\Isolated\Modules\Platform\Support\RegistersPlatformModule;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * The module is not in the root composer.json autoload map; the module manager registers its prefix
 * when it is enabled, which the database-free isolated suite cannot do, so the test registers it.
 */
final class HealthServiceTest extends TestCase
{
    use RegistersPlatformModule;

    /** @param array<string, mixed> $attributes */
    private function session(array $attributes): Session
    {
        $session = new Session(new MockArraySessionStorage());
        foreach ($attributes as $key => $value) {
            $session->set($key, $value);
        }
        return $session;
    }

    public function testSignedInUserIsReturned(): void
    {
        $response = (new HealthService('0.1.0'))->describe(
            $this->session(['authUserID' => 7, 'authUser' => 'admin_a', 'site_id' => 'clinic.example.com'])
        );

        self::assertSame(200, $response->status);
        self::assertTrue($response->body['authenticated']);
        self::assertSame(['id' => 7, 'username' => 'admin_a'], $response->body['user']);
        self::assertSame('clinic.example.com', $response->body['site']);
        self::assertSame('0.1.0', $response->body['version']);
    }

    public function testNumericStringUserIdIsAccepted(): void
    {
        $response = (new HealthService('0.1.0'))->describe($this->session(['authUserID' => '12', 'authUser' => 'dr_b']));

        self::assertSame(200, $response->status);
        self::assertSame(12, $response->body['user']['id']);
    }

    public function testSignedOutSessionGets401WithoutUserDetails(): void
    {
        $response = (new HealthService('0.1.0'))->describe($this->session(['site_id' => 'clinic.example.com']));

        self::assertSame(401, $response->status);
        self::assertFalse($response->body['authenticated']);
        self::assertArrayNotHasKey('user', $response->body);
    }

    public function testIncompleteSessionIsNotTreatedAsSignedIn(): void
    {
        $service = new HealthService('0.1.0');

        self::assertSame(401, $service->describe($this->session(['authUserID' => 0, 'authUser' => 'x']))->status);
        self::assertSame(401, $service->describe($this->session(['authUserID' => 5]))->status);
        self::assertSame(401, $service->describe($this->session(['authUserID' => 'abc', 'authUser' => 'x']))->status);
    }
}
