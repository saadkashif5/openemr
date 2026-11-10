<?php

/**
 * The agent door for approvals: POST /apis/<site>/api/platform_approval with a bearer token.
 *
 *   {"action_type": "platform.sample", "resource": "note/42", "patient_id": 5, "payload": {...}}
 *
 * The token names the agent (client) and the user it acts for; the agent can only propose what that user could do.
 * Nothing runs: the answer is 201 with the approval id, its status (pending) and its correlation id. A person decides
 * later through the session door (public/approvals.php).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Platform\Api;

use JsonException;
use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Modules\Platform\Actor\ActorResolver;
use OpenEMR\Modules\Platform\Actor\CorrelationId;
use OpenEMR\Modules\Platform\Approval\ApprovalException;
use OpenEMR\Modules\Platform\Approval\ApprovalServiceFactory;
use Symfony\Component\HttpFoundation\JsonResponse;

final class ApprovalApi
{
    public function propose(HttpRestRequest $request): JsonResponse
    {
        $correlationId = CorrelationId::fromHeaderOrNew($request->headers->get(CorrelationId::HEADER));
        $headers = [CorrelationId::HEADER => $correlationId, 'Cache-Control' => 'no-store'];

        $actor = (new ActorResolver())->fromRestRequest($request, $correlationId);
        if ($actor === null) {
            return new JsonResponse(['status' => 'refused', 'reason' => 'token_has_no_agent_and_user'], 403, $headers);
        }

        try {
            $body = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new JsonResponse(['status' => 'bad request', 'reason' => 'invalid_json'], 400, $headers);
        }
        $actionType = is_array($body) ? ($body['action_type'] ?? null) : null;
        $resource = is_array($body) ? ($body['resource'] ?? '') : '';
        $payload = is_array($body) ? ($body['payload'] ?? []) : [];
        $patientId = is_array($body) ? ($body['patient_id'] ?? null) : null;
        if (!is_string($actionType) || $actionType === '' || !is_string($resource) || !is_array($payload)
            || ($patientId !== null && !is_int($patientId))) {
            return new JsonResponse(['status' => 'bad request', 'reason' => 'invalid_fields'], 400, $headers);
        }

        try {
            $approval = ApprovalServiceFactory::create()->propose($actor, $actionType, $resource, $payload, $patientId);
        } catch (ApprovalException $e) {
            return new JsonResponse(['status' => 'refused', 'reason' => $e->error->value], $e->error->httpStatus(), $headers);
        }

        return new JsonResponse([
            'status' => 'pending',
            'approval_id' => $approval->id,
            'correlation_id' => $approval->correlationId,
            'expires_at' => $approval->expiresAt->format('c'),
        ], 201, $headers);
    }
}
