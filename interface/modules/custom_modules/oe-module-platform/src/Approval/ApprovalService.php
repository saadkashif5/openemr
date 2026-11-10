<?php

/**
 * Human approval for sensitive actions. An agent (or any caller) proposes an action; a person with the right
 * permission approves or rejects it; an approved action runs once, under the approver's identity.
 *
 * Rules enforced here:
 *  - only a person can decide; an agent never can;
 *  - the approver must hold the permission the action requires;
 *  - the handler receives exactly the payload that was proposed;
 *  - a decision is atomic: an approval runs at most once, and not after it expired;
 *  - every step is written to the audit trail with the actor and the approval's correlation id.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Platform\Approval;

use DateInterval;
use OpenEMR\Modules\Platform\Actor\ActorContext;
use OpenEMR\Modules\Platform\Actor\CorrelationId;
use OpenEMR\Modules\Platform\Audit\AuditTrail;
use Psr\Clock\ClockInterface;
use Throwable;

final readonly class ApprovalService
{
    public function __construct(
        private ApprovalRepository $repository,
        private ApprovalHandlerRegistry $handlers,
        private PermissionChecker $permissions,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private DateInterval $timeToLive,
    ) {
    }

    /**
     * Open an approval request. Nothing is executed.
     *
     * @param array<string, mixed> $payload
     */
    public function propose(ActorContext $proposer, string $actionType, string $resource, array $payload, ?int $patientId = null): Approval
    {
        $handler = $this->handlers->get($actionType) ?? throw ApprovalException::because(ApprovalError::UnknownAction);
        // An agent acts for a person and can never ask for more than that person could do. A proposal the person
        // could not approve themselves is refused, so nobody is asked to approve something they may not allow.
        if (!$this->permissions->allows($proposer, ...$handler->requiredAcl())) {
            $this->audit->record('approval.denied', $proposer, $proposer->correlationId, $patientId, sprintf('action=%s resource=%s proposal refused', $actionType, $resource), false);
            throw ApprovalException::because(ApprovalError::Forbidden);
        }
        $now = $this->clock->now();
        $approval = $this->repository->insert(new NewApproval(
            CorrelationId::generate(),
            $proposer->type,
            $proposer->userId,
            $proposer->clientId ?? '',
            $actionType,
            $resource,
            $patientId,
            $payload,
            $now,
            $now->add($this->timeToLive),
        ));
        $this->audit->record(
            'approval.proposed',
            $proposer,
            $approval->correlationId,
            $patientId,
            sprintf('approval=%d action=%s resource=%s request=%s', $approval->id, $actionType, $resource, $proposer->correlationId)
        );
        return $approval;
    }

    /** What this person could approve right now. */
    public function pendingFor(ActorContext $viewer): array
    {
        if (!$viewer->isHuman()) {
            return [];
        }
        $visible = [];
        foreach ($this->repository->listPending($this->clock->now()) as $approval) {
            $handler = $this->handlers->get($approval->actionType);
            if ($handler !== null && $this->permissions->allows($viewer, ...$handler->requiredAcl())) {
                $visible[] = $approval;
            }
        }
        return $visible;
    }

    /**
     * Approve and run. The action executes once, here, with the approver's identity.
     *
     * @throws ApprovalException
     */
    public function approve(int $id, ActorContext $approver, string $note = ''): Approval
    {
        [$approval, $handler] = $this->decidable($id, $approver);
        $now = $this->clock->now();
        if (!$this->repository->claim($id, ApprovalStatus::Approved, $approver->userId, $note, $now)) {
            throw $this->whyNotDecidable($id, $now);
        }
        $this->audit->record('approval.approved', $approver, $approval->correlationId, $approval->patientId, sprintf('approval=%d action=%s', $id, $approval->actionType));

        try {
            $result = $handler->execute($approval->payload, $approver);
        } catch (Throwable $e) {
            $this->repository->finish($id, ApprovalStatus::Failed, ['error' => 'execution failed'], $this->clock->now());
            $this->audit->record('approval.failed', $approver, $approval->correlationId, $approval->patientId, sprintf('approval=%d action=%s', $id, $approval->actionType), false);
            throw ApprovalException::because(ApprovalError::ExecutionFailed, $e);
        }

        $this->repository->finish($id, ApprovalStatus::Executed, $result, $this->clock->now());
        $this->audit->record('approval.executed', $approver, $approval->correlationId, $approval->patientId, sprintf('approval=%d action=%s', $id, $approval->actionType));
        return $this->repository->find($id) ?? $approval;
    }

    /** @throws ApprovalException */
    public function reject(int $id, ActorContext $approver, string $note = ''): Approval
    {
        [$approval] = $this->decidable($id, $approver);
        $now = $this->clock->now();
        if (!$this->repository->claim($id, ApprovalStatus::Rejected, $approver->userId, $note, $now)) {
            throw $this->whyNotDecidable($id, $now);
        }
        $this->audit->record('approval.rejected', $approver, $approval->correlationId, $approval->patientId, sprintf('approval=%d action=%s', $id, $approval->actionType));
        return $this->repository->find($id) ?? $approval;
    }

    /** Close approvals nobody decided in time. Returns how many. */
    public function expireDue(): int
    {
        return $this->repository->expireDue($this->clock->now());
    }

    /**
     * @return array{0: Approval, 1: ApprovalActionHandler}
     * @throws ApprovalException
     */
    private function decidable(int $id, ActorContext $approver): array
    {
        if (!$approver->isHuman()) {
            throw ApprovalException::because(ApprovalError::AgentCannotDecide);
        }
        $approval = $this->repository->find($id) ?? throw ApprovalException::because(ApprovalError::NotFound);
        $handler = $this->handlers->get($approval->actionType) ?? throw ApprovalException::because(ApprovalError::UnknownAction);
        if (!$this->permissions->allows($approver, ...$handler->requiredAcl())) {
            $this->audit->record('approval.denied', $approver, $approval->correlationId, $approval->patientId, sprintf('approval=%d action=%s', $id, $approval->actionType), false);
            throw ApprovalException::because(ApprovalError::Forbidden);
        }
        return [$approval, $handler];
    }

    private function whyNotDecidable(int $id, \DateTimeImmutable $now): ApprovalException
    {
        $current = $this->repository->find($id);
        if ($current !== null && $current->status === ApprovalStatus::Pending && $current->expiresAt <= $now) {
            $this->repository->expireDue($now);
            return ApprovalException::because(ApprovalError::Expired);
        }
        return ApprovalException::because($current === null ? ApprovalError::NotFound : ApprovalError::NotPending);
    }
}
