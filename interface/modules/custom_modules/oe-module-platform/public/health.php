<?php

/**
 * Health endpoint: GET /interface/modules/custom_modules/oe-module-platform/public/health.php
 *
 * 200 with the session user when signed in, 401 JSON when not. Read-only, never cached.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// Answer 401 ourselves instead of letting globals.php redirect to the login page.
$ignoreAuth = true;
require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Http\CurrentRequest;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\Platform\Actor\CorrelationId;
use OpenEMR\Modules\Platform\Health\HealthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

$correlationId = CorrelationId::fromHeaderOrNew(CurrentRequest::get()->headers->get(CorrelationId::HEADER));
$headers = [CorrelationId::HEADER => $correlationId, 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];

if (!in_array(CurrentRequest::get()->getMethod(), ['GET', 'HEAD'], true)) {
    (new JsonResponse(['status' => 'method not allowed'], Response::HTTP_METHOD_NOT_ALLOWED, $headers + ['Allow' => 'GET, HEAD']))->send();
    exit;
}

$version = (static function (): string {
    require __DIR__ . '/../version.php';
    return "$v_major.$v_minor.$v_patch";
})();

$result = (new HealthService($version))->describe(SessionWrapperFactory::getInstance()->getActiveSession());
(new JsonResponse($result->body, $result->status, $headers))->send();
