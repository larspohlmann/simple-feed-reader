<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Enum\CallVerdict;
use App\Repository\RecommendationRunLogRepository;
use App\Repository\RecommendationSettingsRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Exception\RecommendationRunCancelledException;
use App\Service\Recommendation\Run\RecommendationProfileDistiller;
use App\Tests\DbTestCase;
use App\Tests\Support\BuildsTickContexts;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\StubChatClient;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Against the real repository, entity manager and call recorder, not mocks --
 * mirrors RecommendationRunAdvancerTest's rationale: distill()'s job is to
 * coordinate the history load, the prompt build, the provider call and the
 * settings write, and a mock would have to encode that coordination itself
 * instead of proving it. The provider itself is the one seam worth faking:
 * StubChatClient stands in for it, registered as the container's
 * ChatCompletionClientInterface in the test environment.
 */
final class RecommendationProfileDistillerTest extends DbTestCase
{
    use BuildsTickContexts;

    private User $user;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('profile-distiller@example.test');
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->fixtures->seedReadyAiSettings($this->user);
    }

    public function testUsableReplyWritesProfileToSettingsAndReturnsUsable(): void
    {
        $this->stubChatClient()->queueContent('{"profile":"Likes Rust and homelab."}');

        $outcome = $this->distiller()->distill($this->tick($this->runInRunningState()));

        self::assertTrue($outcome->usable);
        self::assertSame('Likes Rust and homelab.', $outcome->profileText);
        self::assertSame('Likes Rust and homelab.', $this->storedProfileText());
    }

    public function testUnusableReplyReturnsUnusableAndDoesNotTouchSettings(): void
    {
        $this->stubChatClient()->queueContent('not json');

        $outcome = $this->distiller()->distill($this->tick($this->runInRunningState()));

        self::assertFalse($outcome->usable);
        self::assertNull($outcome->profileText);
        self::assertSame('not json', $outcome->requireUnusableReply());
        self::assertNull($this->storedProfileText());
    }

    public function testAUsableReplySettlesItsCallAsUsable(): void
    {
        $this->stubChatClient()->queueContent('{"profile":"Likes Rust and homelab."}');
        $run = $this->runInRunningState();

        $this->distiller()->distill($this->tick($run));

        self::assertSame([CallVerdict::Usable], $this->verdictsOf($run));
    }

    public function testAnUnusableReplySettlesItsCallAsUnusable(): void
    {
        $this->stubChatClient()->queueContent('not json');
        $run = $this->runInRunningState();

        $this->distiller()->distill($this->tick($run));

        self::assertSame([CallVerdict::Unusable], $this->verdictsOf($run));
    }

    /**
     * A tick inside the provider call cannot be interrupted, but the checkpoint after the call settles must stop it
     * writing a profile for a run the user cancelled meanwhile.
     */
    public function testACancellationDuringTheProviderCallStopsBeforeWritingTheProfile(): void
    {
        $run = $this->runInRunningState();
        $this->stubChatClient()->duringNextCall(function () use ($run): void {
            $run->cancel(new \DateTimeImmutable('2026-08-21T10:00:00Z'));
            $this->entityManager->flush();
        });
        $this->stubChatClient()->queueContent('{"profile":"Likes Rust and homelab."}');

        $this->expectException(RecommendationRunCancelledException::class);
        $this->distiller()->distill($this->tick($run));
    }

    public function testACancellationDuringTheProviderCallStopsBeforeReturningAnUnusableOutcome(): void
    {
        $run = $this->runInRunningState();
        $this->stubChatClient()->duringNextCall(function () use ($run): void {
            $run->cancel(new \DateTimeImmutable('2026-08-21T10:00:00Z'));
            $this->entityManager->flush();
        });
        $this->stubChatClient()->queueContent('not json');

        $this->expectException(RecommendationRunCancelledException::class);
        $this->distiller()->distill($this->tick($run));
    }

    /**
     * A transport failure has to abort the log row it opened, not merely
     * propagate — a verdict left null forever reads to the debug panel as
     * "still streaming" (mirrors RecommendationConsolidationResolverTest's own
     * testTransportFailureAbortsTheOpenLogRow).
     */
    public function testTransportFailureAbortsTheOpenLogRow(): void
    {
        $this->fixtures->debugEnabledSettings($this->user);
        $run = $this->runInRunningState();
        $this->stubChatClient()->queueFailure(new \RuntimeException('gone'));

        try {
            $this->distiller()->distill($this->tick($run));
            self::fail('The transport failure must propagate.');
        } catch (\RuntimeException) {
        }

        /** @var RecommendationRunLogRepository $logs */
        $logs = self::getContainer()->get(RecommendationRunLogRepository::class);
        $rows = $logs->listForRun($this->user, $run->requireId());

        self::assertSame([CallVerdict::TransportFailed], array_column($rows, 'verdict'));
        self::assertSame('gone', $rows[0]['errorDetail']);
    }

    /**
     * `$settings->getModel() ?? ''` only falls back to '' when the model is
     * genuinely unset — a configured model must reach the recorded call
     * unchanged, not be discarded in favour of the fallback.
     */
    public function testTheRecordedCallCarriesTheConfiguredModel(): void
    {
        $this->fixtures->debugEnabledSettings($this->user);
        $run = $this->runInRunningState();
        $this->stubChatClient()->queueContent('{"profile":"Likes Rust and homelab."}');

        $this->distiller()->distill($this->tick($run));

        $log = $this->entityManager->getRepository(RecommendationRunLog::class)->findOneBy(['run' => $run]);
        self::assertNotNull($log);
        $decoded = json_decode($log->getRequestBody(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('m', $decoded['model']);
    }

    public function testDistillSendsTheDistillationSchema(): void
    {
        $this->stubChatClient()->queueContent('{"profile":"Likes Rust and homelab."}');

        $this->distiller()->distill($this->tick($this->runInRunningState()));

        $calls = $this->stubChatClient()->calls();
        self::assertCount(1, $calls);
        self::assertSame('profile', $calls[0]['responseSchemaName']);
    }

    private function runInRunningState(): RecommendationRun
    {
        $run = $this->fixtures->createRun($this->user);
        $run->snapshot([[1]]);
        $this->entityManager->flush();

        return $run;
    }

    /** @return list<?CallVerdict> */
    private function verdictsOf(RecommendationRun $run): array
    {
        /** @var RecommendationRunLogRepository $logs */
        $logs = self::getContainer()->get(RecommendationRunLogRepository::class);

        return array_column($logs->listForRun($this->user, $run->requireId()), 'verdict');
    }

    private function storedProfileText(): ?string
    {
        /** @var RecommendationSettingsRepository $repository */
        $repository = self::getContainer()->get(RecommendationSettingsRepository::class);

        return $repository->findForUser($this->user)?->values()->profileText;
    }

    private function distiller(): RecommendationProfileDistiller
    {
        /** @var RecommendationProfileDistiller $distiller */
        $distiller = self::getContainer()->get(RecommendationProfileDistiller::class);

        return $distiller;
    }

    private function stubChatClient(): StubChatClient
    {
        /** @var StubChatClient $client */
        $client = self::getContainer()->get(StubChatClient::class);

        return $client;
    }
}
