<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Entity\Entry;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Enum\CallVerdict;
use App\Repository\RecommendationRunLogRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Exception\RecommendationRunCancelledException;
use App\Service\Recommendation\Run\Model\ConsolidationOutcomeModel;
use App\Service\Recommendation\Run\RecommendationConsolidationResolver;
use App\Tests\DbTestCase;
use App\Tests\Support\BuildsTickContexts;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\StubChatClient;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RecommendationConsolidationResolverTest extends DbTestCase
{
    use BuildsTickContexts;

    private User $user;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('consolidation-resolver@example.test');
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->fixtures->seedReadyAiSettings($this->user);
    }

    public function testUsableReplyDropsDuplicatesAndSortsByNewScore(): void
    {
        [$firstEntry, $secondEntry] = $this->fixtures->seedFeedWithEntries($this->user, 2);
        $firstId = $this->idOf($firstEntry);
        $secondId = $this->idOf($secondEntry);

        $run = $this->runWithWinners([
            ['id' => $firstId, 'score' => 400, 'reason' => ''],
            ['id' => $secondId, 'score' => 700, 'reason' => ''],
        ]);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [
                ['id' => $firstId, 'score' => 950, 'reason' => 'On Rust.'],
                ['id' => $secondId, 'score' => 300, 'reason' => 'Weak fit.'],
            ],
            'duplicates' => [$secondId],
        ], \JSON_THROW_ON_ERROR));

        $outcome = $this->resolveConsolidation($run);

        self::assertTrue($outcome->usable);
        self::assertSame([$firstId], array_map(static fn (array $pick): int => $pick['id'], $outcome->ranked));
        self::assertSame('On Rust.', $outcome->ranked[0]['reason']);
        self::assertSame(950, $outcome->ranked[0]['score']);
    }

    /** The only case whose reply scores invert two survivors' batch order, so it alone exercises the sort. */
    public function testUsableReplySortsSurvivorsByReplyScoreWhenOrderInverts(): void
    {
        [$firstEntry, $secondEntry] = $this->fixtures->seedFeedWithEntries($this->user, 2);
        $firstId = $this->idOf($firstEntry);
        $secondId = $this->idOf($secondEntry);

        // Batch order ranks first ahead of second (700 > 400); the reply
        // inverts that by scoring second higher than first.
        $run = $this->runWithWinners([
            ['id' => $firstId, 'score' => 700, 'reason' => ''],
            ['id' => $secondId, 'score' => 400, 'reason' => ''],
        ]);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [
                ['id' => $firstId, 'score' => 200, 'reason' => 'Weaker than it looked.'],
                ['id' => $secondId, 'score' => 800, 'reason' => 'Stronger than it looked.'],
            ],
            'duplicates' => [],
        ], \JSON_THROW_ON_ERROR));

        $outcome = $this->resolveConsolidation($run);

        self::assertTrue($outcome->usable);
        self::assertSame(
            [$secondId, $firstId],
            array_map(static fn (array $pick): int => $pick['id'], $outcome->ranked),
        );
        self::assertSame(800, $outcome->ranked[0]['score']);
        self::assertSame(200, $outcome->ranked[1]['score']);
    }

    /**
     * The parser may return fewer picks than it was shown. A pool entry the reply neither scores nor names as a
     * duplicate is dropped: every recommendation shown carries a reason the consolidation wrote.
     */
    public function testUsableReplyDropsASurvivorTheReplyDidNotMention(): void
    {
        [$firstEntry, $secondEntry, $thirdEntry] = $this->fixtures->seedFeedWithEntries($this->user, 3);
        $firstId = $this->idOf($firstEntry);
        $secondId = $this->idOf($secondEntry);
        $thirdId = $this->idOf($thirdEntry);

        $run = $this->runWithWinners([
            ['id' => $firstId, 'score' => 700, 'reason' => ''],
            ['id' => $secondId, 'score' => 500, 'reason' => ''],
            ['id' => $thirdId, 'score' => 300, 'reason' => ''],
        ]);

        // The reply scores only the first two and names no duplicates, leaving the third unmentioned.
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [
                ['id' => $firstId, 'score' => 900, 'reason' => 'Great fit.'],
                ['id' => $secondId, 'score' => 100, 'reason' => 'Poor fit.'],
            ],
            'duplicates' => [],
        ], \JSON_THROW_ON_ERROR));

        $outcome = $this->resolveConsolidation($run);

        self::assertTrue($outcome->usable);
        $ids = array_map(static fn (array $pick): int => $pick['id'], $outcome->ranked);
        self::assertSame([$firstId, $secondId], $ids);
        self::assertNotContains($thirdId, $ids);
        foreach ($outcome->ranked as $pick) {
            self::assertNotSame('', $pick['reason']);
        }
    }

    public function testUnusableReplyFallsBackToBatchScorePoolWithEmptyReasons(): void
    {
        [$firstEntry, $secondEntry] = $this->fixtures->seedFeedWithEntries($this->user, 2);
        $firstId = $this->idOf($firstEntry);
        $secondId = $this->idOf($secondEntry);

        $run = $this->runWithWinners([
            ['id' => $firstId, 'score' => 700, 'reason' => ''],
            ['id' => $secondId, 'score' => 400, 'reason' => ''],
        ]);

        $this->stubChatClient()->queueContent('not json');

        $outcome = $this->resolveConsolidation($run);

        self::assertFalse($outcome->usable);
        self::assertSame(
            [$firstId, $secondId],
            array_map(static fn (array $pick): int => $pick['id'], $outcome->requireFallbackPool()),
        );
        self::assertSame('not json', $outcome->requireUnusableReply());
        self::assertSame(['', ''], array_map(
            static fn (array $pick): string => $pick['reason'],
            $outcome->requireFallbackPool(),
        ));
    }

    public function testAUsableReplySettlesItsCallAsUsable(): void
    {
        [$entry] = $this->fixtures->seedFeedWithEntries($this->user, 1);
        $id = $this->idOf($entry);
        $run = $this->runWithWinners([['id' => $id, 'score' => 500, 'reason' => '']]);
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $id, 'score' => 600, 'reason' => 'Fits.']],
            'duplicates' => [],
        ], \JSON_THROW_ON_ERROR));

        $this->resolveConsolidation($run);

        self::assertSame([CallVerdict::Usable], $this->verdictsOf($run));
    }

    public function testAnUnusableReplySettlesItsCallAsUnusable(): void
    {
        [$entry] = $this->fixtures->seedFeedWithEntries($this->user, 1);
        $run = $this->runWithWinners([['id' => $this->idOf($entry), 'score' => 500, 'reason' => '']]);
        $this->stubChatClient()->queueContent('not json');

        $this->resolveConsolidation($run);

        self::assertSame([CallVerdict::Unusable], $this->verdictsOf($run));
    }

    public function testACancellationDuringTheProviderCallStopsBeforeReturningAnUnusableOutcome(): void
    {
        [$entry] = $this->fixtures->seedFeedWithEntries($this->user, 1);
        $run = $this->runWithWinners([['id' => $this->idOf($entry), 'score' => 500, 'reason' => '']]);
        $this->stubChatClient()->duringNextCall(function () use ($run): void {
            $run->cancel(new \DateTimeImmutable('2026-08-21T10:00:00Z'));
            $this->entityManager->flush();
        });
        $this->stubChatClient()->queueContent('not json');

        $this->expectException(RecommendationRunCancelledException::class);
        $this->resolveConsolidation($run);
    }

    public function testTransportFailureAbortsTheOpenLogRow(): void
    {
        $this->fixtures->debugEnabledSettings($this->user);
        [$entry] = $this->fixtures->seedFeedWithEntries($this->user, 1);
        $id = $this->idOf($entry);
        $run = $this->runWithWinners([['id' => $id, 'score' => 500, 'reason' => '']]);

        $this->stubChatClient()->queueFailure(new \RuntimeException('gone'));

        try {
            $this->resolveConsolidation($run);
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
        [$entry] = $this->fixtures->seedFeedWithEntries($this->user, 1);
        $id = $this->idOf($entry);
        $run = $this->runWithWinners([['id' => $id, 'score' => 500, 'reason' => '']]);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $id, 'score' => 600, 'reason' => 'Fits.']],
            'duplicates' => [],
        ], \JSON_THROW_ON_ERROR));

        $this->resolveConsolidation($run);

        $log = $this->entityManager->getRepository(RecommendationRunLog::class)->findOneBy(['run' => $run]);
        self::assertNotNull($log);
        $decoded = json_decode($log->getRequestBody(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('m', $decoded['model']);
    }

    public function testConsolidationSendsTheConsolidationSchema(): void
    {
        [$entry] = $this->fixtures->seedFeedWithEntries($this->user, 1);
        $id = $this->idOf($entry);

        $run = $this->runWithWinners([['id' => $id, 'score' => 500, 'reason' => '']]);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $id, 'score' => 600, 'reason' => 'Fits.']],
            'duplicates' => [],
        ], \JSON_THROW_ON_ERROR));

        $this->resolver()->resolve($this->tick($run));

        $calls = $this->stubChatClient()->calls();
        self::assertCount(1, $calls);
        self::assertSame('recommendations', $calls[0]['responseSchemaName']);
    }

    /**
     * An unusable reply hands back stillPresent()'s pool as the fallback unchanged, so it must stay a list after a
     * middle entry drops.
     */
    public function testUnusableReplyFallbackPoolStaysAListAfterAMiddleEntryIsPruned(): void
    {
        [$firstEntry, $secondEntry, $thirdEntry] = $this->fixtures->seedFeedWithEntries($this->user, 3);
        $firstId = $this->idOf($firstEntry);
        $secondId = $this->idOf($secondEntry);
        $thirdId = $this->idOf($thirdEntry);

        $run = $this->runWithWinners([
            ['id' => $firstId, 'score' => 700, 'reason' => ''],
            ['id' => $secondId, 'score' => 500, 'reason' => ''],
            ['id' => $thirdId, 'score' => 300, 'reason' => ''],
        ]);

        $middle = $this->entityManager->getRepository(Entry::class)->find($secondId);
        self::assertNotNull($middle);
        $this->entityManager->remove($middle);
        $this->entityManager->flush();

        $this->stubChatClient()->queueContent('not json');

        $outcome = $this->resolveConsolidation($run);

        self::assertFalse($outcome->usable);
        // Keys, not merely values: array_filter() alone would leave (0, 2), a
        // gap only array_values() closes.
        self::assertSame([0, 1], array_keys($outcome->requireFallbackPool()));
        self::assertSame(
            [$firstId, $thirdId],
            array_map(static fn (array $pick): int => $pick['id'], $outcome->requireFallbackPool()),
        );
    }

    public function testAllWinnersPrunedFinalizesWithoutAProviderCall(): void
    {
        $entries = $this->fixtures->seedFeedWithEntries($this->user, 1);
        $id = $this->idOf($entries[0]);

        $run = $this->runWithWinners([['id' => $id, 'score' => 500, 'reason' => '']]);

        $entry = $this->entityManager->getRepository(Entry::class)->find($id);
        self::assertNotNull($entry);
        $this->entityManager->remove($entry);
        $this->entityManager->flush();

        $outcome = $this->resolveConsolidation($run);

        self::assertTrue($outcome->usable);
        self::assertSame([], $outcome->ranked);
        self::assertCount(0, $this->stubChatClient()->calls());
    }

    /**
     * @param list<array{id: int, score: int, reason: string}> $winners
     */
    private function runWithWinners(array $winners): RecommendationRun
    {
        $run = $this->fixtures->createRun($this->user);
        $run->snapshot([array_column($winners, 'id')]);
        $run->recordBatchWinners($winners);
        $this->entityManager->flush();

        return $run;
    }

    private function resolveConsolidation(RecommendationRun $run): ConsolidationOutcomeModel
    {
        return $this->resolver()->resolve($this->tick($run));
    }

    private function idOf(Entry $entry): int
    {
        return $entry->requireId();
    }

    /** @return list<?CallVerdict> */
    private function verdictsOf(RecommendationRun $run): array
    {
        /** @var RecommendationRunLogRepository $logs */
        $logs = self::getContainer()->get(RecommendationRunLogRepository::class);

        return array_column($logs->listForRun($this->user, $run->requireId()), 'verdict');
    }

    private function resolver(): RecommendationConsolidationResolver
    {
        /** @var RecommendationConsolidationResolver $resolver */
        $resolver = self::getContainer()->get(RecommendationConsolidationResolver::class);

        return $resolver;
    }

    private function stubChatClient(): StubChatClient
    {
        /** @var StubChatClient $client */
        $client = self::getContainer()->get(StubChatClient::class);

        return $client;
    }
}
