<?php

/**
 * Approvals endpoint (session, same origin; used by the shell and by people).
 *
 *   GET  .../approvals.php                       pending approvals this person may decide, and a CSRF token
 *   POST .../approvals.php  action=approve|reject  id=<n>  note=<text>  csrf_token_form=<token>
 *
 * Only a signed-in person can decide; an approved action runs once, as that person. 401 when signed out,
 * 403 without the permission or for an agent, 404 unknown, 409 already decided or expired.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// Answer 401 ourselves instead of redirecting to the login page.
$ignoreAuth = true;
require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Http\CurrentRequest;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\Platform\Actor\ActorResolver;
use OpenEMR\Modules\Platform\Actor\CorrelationId;
use OpenEMR\Modules\Platform\Approval\Approval;
use OpenEMR\Modules\Platform\Approval\ApprovalException;
use OpenEMR\Modules\Platform\Approval\ApprovalServiceFactory;
use Symfony\Component\HttpFoundation\JsonResponse;

$request = CurrentRequest::get();
$correlationId = CorrelationId::fromHeaderOrNew($request->headers->get(CorrelationId::HEADER));
$headers = [CorrelationId::HEADER => $correlationId, 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];
$reply = static function (array $body, int $status) use ($headers): never {
    (new JsonResponse($body, $status, $headers))->send();
    exit;
};
$describe = static fn(Approval $a): array => [
    'id' => $a->id,
    'correlation_id' => $a->correlationId,
    'status' => $a->status->value,
    'action' => $a->actionType,
    'resource' => $a->resource,
    'patient_id' => $a->patientId,
    'payload' => $a->payload,
    'proposed_by' => $a->proposedByType->value . ($a->proposedByClientId !== '' ? ':' . $a->proposedByClientId : ''),
    'created_at' => $a->createdAt->format('c'),
    'expires_at' => $a->expiresAt->format('c'),
    'result' => $a->result,
];

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$actor = (new ActorResolver())->fromSession($session, $correlationId);
if ($actor === null) {
    $reply(['status' => 'unauthenticated'], 401);
}
$service = ApprovalServiceFactory::create();

$method = $request->getMethod();
if ($method === 'GET' || $method === 'HEAD') {
    $reply([
        'status' => 'ok',
        'csrf_token' => CsrfUtils::collectCsrfToken($session),
        'pending' => array_map($describe, $service->pendingFor($actor)),
    ], 200);
}
if ($method !== 'POST') {
    $reply(['status' => 'method not allowed'], 405);
}

if (!CsrfUtils::verifyCsrfToken($request->request->getString('csrf_token_form'), $session)) {
    $reply(['status' => 'csrf'], 403);
}
$action = $request->request->getString('action');
$id = $request->request->getInt('id');
$note = $request->request->getString('note');
if ($id <= 0 || !in_array($action, ['approve', 'reject'], true)) {
    $reply(['status' => 'bad request'], 400);
}

try {
    $approval = $action === 'approve' ? $service->approve($id, $actor, $note) : $service->reject($id, $actor, $note);
} catch (ApprovalException $e) {
    $reply(['status' => 'refused', 'reason' => $e->error->value], $e->error->httpStatus());
}
$reply(['status' => 'ok', 'approval' => $describe($approval)], 200);
