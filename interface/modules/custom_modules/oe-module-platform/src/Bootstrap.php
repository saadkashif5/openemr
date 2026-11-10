<?php

/**
 * Registers the platform module's token API (the agent door) with OpenEMR's REST API: one route and the scope a
 * token needs for it. Our own screens use the session endpoints in public/; both call the same services.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Platform;

use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Events\RestApiExtend\RestApiCreateEvent;
use OpenEMR\Events\RestApiExtend\RestApiScopeEvent;
use OpenEMR\Modules\Platform\Api\ApprovalApi;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final readonly class Bootstrap
{
    /** The resource name in the route and in the scope, for example user/platform_approval.write. */
    public const RESOURCE = 'platform_approval';

    public function __construct(private EventDispatcherInterface $dispatcher)
    {
    }

    public function subscribeToEvents(): void
    {
        $this->dispatcher->addListener(RestApiCreateEvent::EVENT_HANDLE, $this->addRoutes(...));
        $this->dispatcher->addListener(RestApiScopeEvent::EVENT_TYPE_GET_SUPPORTED_SCOPES, $this->addScopes(...));
    }

    public function addRoutes(RestApiCreateEvent $event): RestApiCreateEvent
    {
        $event->addToRouteMap(
            'POST /api/' . self::RESOURCE,
            static fn(HttpRestRequest $request) => (new ApprovalApi())->propose($request)
        );
        return $event;
    }

    public function addScopes(RestApiScopeEvent $event): RestApiScopeEvent
    {
        if ($event->getApiType() === RestApiScopeEvent::API_TYPE_STANDARD) {
            $scopes = $event->getScopes();
            $scopes[] = 'user/' . self::RESOURCE . '.write';
            $event->setScopes($scopes);
        }
        return $event;
    }
}
