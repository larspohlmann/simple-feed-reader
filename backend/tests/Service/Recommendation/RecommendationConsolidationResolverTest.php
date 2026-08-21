<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\AiProviderSettings;
use App\Entity\Entry;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Repository\RecommendationRunLogRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\ConsolidationOutcome;
use App\Service\Recommendation\EffectiveRecommendationSettings;
use App\Service\Recommendation\RecommendationConsolidationResolver;
use App\Service\Recommendation\RecommendationSettingsResolver;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\StubChatClient;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Against the real ranker, candidate loader, history loader, prompt builder
 * and call recorder, not mocks -- mirrors RecommendationProfileDistillerTest's
 * rationale: resolve()'s job is to coordinate the pool cut, the prompt build,
 * the provider call and the ranked-list assembly, and a mock would have to
 * encode that coordination itself instead of proving it. The provider itself
 * is the one seam worth faking: StubChatClient stands in for it, registered
 * as the container's ChatCompletionClient in the test environment.
 */
final class RecommendationConsolidationResolverTest extends DbTestCase
{
    private User $user;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->em, $hasher))->create('consolidation-resolver@example.test');
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->em, $cipher);
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

    /**
     * Both survivors pass through untouched by duplicate-dropping (no
     * `duplicates` named at all), so a mutant that flips the usort comparator
     * or deletes the sort outright still passes every other test in this
     * file -- only this one, with 2+ survivors whose reply scores invert
     * their batch order, actually exercises the sort.
     */
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
     * RecommendationConsolidationParser::salvagePicks() can legitimately
     * return fewer picks than the pool it was shown, so a pool entry the
     * reply neither scores nor names a duplicate is a real, reachable case.
     * It must survive the fallback to its own batch score and empty reason
     * rather than silently vanishing.
     */
    public function testUsableReplyKeepsASurvivorTheReplyDidNotMentionAtItsBatchScore(): void
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

        // The reply scores only the first two and names no duplicates: the
        // third pool entry is left unmentioned entirely.
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [
                ['id' => $firstId, 'score' => 900, 'reason' => 'Great fit.'],
                ['id' => $secondId, 'score' => 100, 'reason' => 'Poor fit.'],
            ],
            'duplicates' => [],
        ], \JSON_THROW_ON_ERROR));

        $outcome = $this->resolveConsolidation($run);

        self::assertTrue($outcome->usable);
        $survivor = self::findPick($outcome->ranked, $thirdId);
        self::assertSame(300, $survivor['score']);
        self::assertSame('', $survivor['reason']);
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

    /**
     * A transport failure has to abort the log row it opened, not merely
     * propagate — a verdict left null forever reads to the debug panel as
     * "still streaming" (mirrors RecommendationRunAdvancerTest's own
     * testATransportFailureStampsItsLogRow, but exercised here at the
     * resolver's own catch rather than through the full advancer).
     */
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
        $rows = $logs->listForRun($this->user, $run->getId() ?? throw new \LogicException('Run was never saved.'));

        self::assertSame(['transport-failed'], array_column($rows, 'verdict'));
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

        $log = $this->em->getRepository(RecommendationRunLog::class)->findOneBy(['run' => $run]);
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

        $this->resolver()->resolve($run, $this->activeAiSettings(), $this->userId(), 50, $this->effectiveSettings());

        $calls = $this->stubChatClient()->calls();
        self::assertCount(1, $calls);
        self::assertSame('recommendations', $calls[0]['responseSchemaName']);
    }

    /**
     * stillPresent() must leave the pool a genuine list even when the entry
     * it drops sits in the middle: array_filter() alone keeps the surviving
     * keys as they were (0, 2), and an unusable reply exposes that raw pool
     * as the fallback pool unrenormalized by anything downstream.
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

        $middle = $this->em->getRepository(Entry::class)->find($secondId);
        self::assertNotNull($middle);
        $this->em->remove($middle);
        $this->em->flush();

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

        $entry = $this->em->getRepository(Entry::class)->find($id);
        self::assertNotNull($entry);
        $this->em->remove($entry);
        $this->em->flush();

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
        $this->em->flush();

        return $run;
    }

    private function resolveConsolidation(RecommendationRun $run): ConsolidationOutcome
    {
        return $this->resolver()->resolve(
            $run,
            $this->activeAiSettings(),
            $this->userId(),
            50,
            $this->effectiveSettings(),
        );
    }

    private function idOf(Entry $entry): int
    {
        return $entry->getId() ?? throw new \LogicException('Entry was never saved.');
    }

    /**
     * @param list<array{id: int, score: int, reason: string}> $ranked
     *
     * @return array{id: int, score: int, reason: string}
     */
    private static function findPick(array $ranked, int $id): array
    {
        foreach ($ranked as $pick) {
            if ($pick['id'] === $id) {
                return $pick;
            }
        }

        self::fail(\sprintf('Expected id %d in the ranked list.', $id));
    }

    private function activeAiSettings(): AiProviderSettings
    {
        $settings = $this->user->getActiveAiProviderSettings();
        self::assertNotNull($settings);

        return $settings;
    }

    private function effectiveSettings(): EffectiveRecommendationSettings
    {
        /** @var RecommendationSettingsResolver $resolver */
        $resolver = self::getContainer()->get(RecommendationSettingsResolver::class);

        return $resolver->forUser($this->user);
    }

    private function userId(): int
    {
        return $this->user->getId() ?? throw new \LogicException('User was never saved.');
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
