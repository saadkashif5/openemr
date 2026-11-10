<?php

/**
 * The human-approval rules: an agent proposes, a permitted person decides, an approved action runs once as that
 * person, and every step is audited with the actor and the approval's correlation id.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\Platform;

use DateInterval;
use DateTimeImmutable;
use Lcobucci\Clock\FrozenClock;
use OpenEMR\Modules\Platform\Actor\ActorContext;
use OpenEMR\Modules\Platform\Actor\CorrelationId;
use OpenEMR\Modules\Platform\Approval\ApprovalError;
use OpenEMR\Modules\Platform\Approval\ApprovalException;
use OpenEMR\Modules\Platform\Approval\ApprovalHandlerRegistry;
use OpenEMR\Modules\Platform\Approval\ApprovalService;
use OpenEMR\Modules\Platform\Approval\ApprovalStatus;
use OpenEMR\Modules\Platform\Approval\PermissionChecker;
use OpenEMR\Tests\Isolated\Modules\Platform\Support\CountingHandler;
use OpenEMR\Tests\Isolated\Modules\Platform\Support\InMemoryApprovalRepository;
use OpenEMR\Tests\Isolated\Modules\Platform\Support\RecordingAuditTrail;
use OpenEMR\Tests\Isolated\Modules\Platform\Support\RegistersPlatformModule;
use PHPUnit\Framework\TestCase;

final class ApprovalServiceTest extends TestCase
{
    use RegistersPlatformModule;

    private InMemoryApprovalRepository $repository;
    private RecordingAuditTrail $audit;
    private CountingHandler $handler;
    private ApprovalService $service;
    private FrozenClock $clock;
    /** @var list<string> usernames that hold the permission */
    private array $permitted = ['dr_b'];

    protected function setUp(): void
    {
        $this->repository = new InMemoryApprovalRepository();
        $this->audit = new RecordingAuditTrail();
        $this->handler = new CountingHandler();
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-11-10 09:00:00'));
        $this->service = $this->makeService($this->handler);
    }

    private function makeService(CountingHandler $handler): ApprovalService
    {
        $permissions = new class ($this->permitted) implements PermissionChecker {
            /** @param list<string> $permitted */
            public function __construct(private readonly array $permitted)
            {
            }

            public function allows(ActorContext $actor, string $section, string $value): bool
            {
                return in_array($actor->username, $this->permitted, true);
            }
        };
        return new ApprovalService(
            $this->repository,
            new ApprovalHandlerRegistry($handler),
            $permissions,
            $this->audit,
            $this->clock,
            new DateInterval('PT1H'),
        );
    }

    private function agent(): ActorContext
    {
        return ActorContext::agent('scribe-agent', 7, 'dr_b', CorrelationId::generate());
    }

    private function person(string $username = 'dr_b', int $id = 7): ActorContext
    {
        return ActorContext::human($id, $username, CorrelationId::generate());
    }

    public function testProposalWaitsAndRunsNothing(): void
    {
        $approval = $this->service->propose($this->agent(), 'test.action', 'note/12', ['text' => 'sign this'], 5);

        self::assertSame(ApprovalStatus::Pending, $approval->status);
        self::assertSame(0, $this->handler->runs);
        self::assertSame(['approval.proposed'], $this->audit->events());
        self::assertSame('agent:scribe-agent for:dr_b', $this->audit->entries[0]['actor']);
        self::assertSame($approval->correlationId, $this->audit->entries[0]['correlation']);
        self::assertTrue(CorrelationId::isValid($approval->correlationId));
    }

    public function testAnAgentCannotProposeWhatItsUserCouldNotDoThemselves(): void
    {
        $agentForFrontDesk = ActorContext::agent('scribe-agent', 9, 'front_desk', CorrelationId::generate());

        $this->expectApprovalError(ApprovalError::Forbidden);
        try {
            $this->service->propose($agentForFrontDesk, 'test.action', 'note/12', []);
        } finally {
            self::assertCount(0, $this->repository->listPending($this->clock->now()));
            self::assertSame(['approval.denied'], $this->audit->events());
            self::assertFalse($this->audit->entries[0]['success']);
        }
    }

    public function testApprovedActionRunsOnceAsTheApproverWithTheProposedPayload(): void
    {
        $payload = ['text' => 'sign this', 'note_id' => 12];
        $approval = $this->service->propose($this->agent(), 'test.action', 'note/12', $payload, 5);

        $done = $this->service->approve($approval->id, $this->person('dr_b', 7));

        self::assertSame(ApprovalStatus::Executed, $done->status);
        self::assertSame(1, $this->handler->runs);
        self::assertSame($payload, $this->handler->lastPayload);
        self::assertSame('dr_b', $this->handler->lastApprover);
        self::assertSame(['done' => true], $done->result);
        self::assertSame(['approval.proposed', 'approval.approved', 'approval.executed'], $this->audit->events());
    }

    public function testEveryStepSharesTheApprovalsCorrelationIdAndNamesTheActor(): void
    {
        $approval = $this->service->propose($this->agent(), 'test.action', 'note/12', [], 5);
        $this->service->approve($approval->id, $this->person());

        foreach ($this->audit->entries as $entry) {
            self::assertSame($approval->correlationId, $entry['correlation']);
            self::assertSame(5, $entry['patient']);
        }
        self::assertSame('human:dr_b', $this->audit->entries[1]['actor']);
    }

    public function testASecondApprovalDoesNotRunItAgain(): void
    {
        $approval = $this->service->propose($this->agent(), 'test.action', 'x', []);
        $this->service->approve($approval->id, $this->person());

        $this->expectApprovalError(ApprovalError::NotPending);
        try {
            $this->service->approve($approval->id, $this->person());
        } finally {
            self::assertSame(1, $this->handler->runs);
        }
    }

    public function testRejectedActionNeverRuns(): void
    {
        $approval = $this->service->propose($this->agent(), 'test.action', 'x', []);

        $rejected = $this->service->reject($approval->id, $this->person(), 'not needed');

        self::assertSame(ApprovalStatus::Rejected, $rejected->status);
        self::assertSame(0, $this->handler->runs);
        $this->expectApprovalError(ApprovalError::NotPending);
        $this->service->approve($approval->id, $this->person());
    }

    public function testAnAgentCanNeverDecide(): void
    {
        $approval = $this->service->propose($this->agent(), 'test.action', 'x', []);

        $this->expectApprovalError(ApprovalError::AgentCannotDecide);
        try {
            $this->service->approve($approval->id, $this->agent());
        } finally {
            self::assertSame(0, $this->handler->runs);
            self::assertSame(ApprovalStatus::Pending, $this->repository->find($approval->id)?->status);
        }
    }

    public function testApproverWithoutThePermissionIsRefusedAndTheActionStaysPending(): void
    {
        $approval = $this->service->propose($this->agent(), 'test.action', 'x', []);

        $this->expectApprovalError(ApprovalError::Forbidden);
        try {
            $this->service->approve($approval->id, $this->person('front_desk', 9));
        } finally {
            self::assertSame(0, $this->handler->runs);
            self::assertSame(ApprovalStatus::Pending, $this->repository->find($approval->id)?->status);
            self::assertContains('approval.denied', $this->audit->events());
            self::assertFalse($this->audit->entries[array_key_last($this->audit->entries)]['success']);
        }
    }

    public function testAnApprovalCannotBeDecidedAfterItExpired(): void
    {
        $approval = $this->service->propose($this->agent(), 'test.action', 'x', []);
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-11-10 10:00:01'));
        $service = $this->makeService($this->handler);

        $this->expectApprovalError(ApprovalError::Expired);
        try {
            $service->approve($approval->id, $this->person());
        } finally {
            self::assertSame(0, $this->handler->runs);
            self::assertSame(ApprovalStatus::Expired, $this->repository->find($approval->id)?->status);
        }
    }

    public function testExpireDueClosesOnlyOverdueApprovals(): void
    {
        $old = $this->service->propose($this->agent(), 'test.action', 'old', []);
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-11-10 09:45:00'));
        $service = $this->makeService($this->handler);
        $recent = $service->propose($this->agent(), 'test.action', 'recent', []);
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-11-10 10:10:00'));
        $service = $this->makeService($this->handler);

        self::assertSame(1, $service->expireDue());
        self::assertSame(ApprovalStatus::Expired, $this->repository->find($old->id)?->status);
        self::assertSame(ApprovalStatus::Pending, $this->repository->find($recent->id)?->status);
    }

    public function testAFailingActionIsRecordedAsFailedWithoutLeakingTheReason(): void
    {
        $failing = new CountingHandler(true);
        $service = $this->makeService($failing);
        $approval = $service->propose($this->agent(), 'test.action', 'x', []);

        try {
            $service->approve($approval->id, $this->person());
            self::fail('Expected the approval to be refused');
        } catch (ApprovalException $e) {
            self::assertSame(ApprovalError::ExecutionFailed, $e->error);
            self::assertStringNotContainsString('internal detail', $e->getMessage());
        }
        $stored = $this->repository->find($approval->id);
        self::assertSame(ApprovalStatus::Failed, $stored?->status);
        self::assertSame(['error' => 'execution failed'], $stored?->result);
        self::assertContains('approval.failed', $this->audit->events());
    }

    public function testUnknownActionTypeCannotBeProposed(): void
    {
        $this->expectApprovalError(ApprovalError::UnknownAction);
        $this->service->propose($this->agent(), 'nope', 'x', []);
    }

    public function testUnknownApprovalIsNotFound(): void
    {
        $this->expectApprovalError(ApprovalError::NotFound);
        $this->service->approve(999, $this->person());
    }

    public function testPendingListShowsOnlyWhatThePersonMayApprove(): void
    {
        $this->service->propose($this->agent(), 'test.action', 'x', []);

        self::assertCount(1, $this->service->pendingFor($this->person('dr_b')));
        self::assertCount(0, $this->service->pendingFor($this->person('front_desk', 9)));
        self::assertCount(0, $this->service->pendingFor($this->agent()));
    }

    public function testCorrelationIdFromAHeaderIsKeptOnlyWhenItIsAUuid(): void
    {
        $given = '3f2b8a54-6a1e-4d1f-9c55-0a8d6f4b1c22';

        self::assertSame($given, CorrelationId::fromHeaderOrNew($given));
        self::assertNotSame('not-an-id', CorrelationId::fromHeaderOrNew('not-an-id'));
        self::assertTrue(CorrelationId::isValid(CorrelationId::fromHeaderOrNew(null)));
    }

    private function expectApprovalError(ApprovalError $error): void
    {
        $this->expectException(ApprovalException::class);
        $this->expectExceptionMessage($error->value);
    }
}
