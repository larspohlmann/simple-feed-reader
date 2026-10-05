<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Jev;

use App\Entity\Entry;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Enum\CallPhase;
use App\Enum\CallVerdict;
use App\Enum\RecommendationEngineKind;
use App\Http\RecommendationFeedJson;
use App\Repository\ForYouFeedQuery;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Feed\ForYouFeed;
use App\Service\Recommendation\Jev\JevRecommendationEngine;
use App\Service\Recommendation\Jev\Support\QuestionId;
use App\Service\Recommendation\Profile\ProfileConnections;
use App\Service\Recommendation\Run\SnapshotPhase;
use App\Tests\DbTestCase;
use App\Tests\Support\DrivesRecommendationRuns;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StubSystemOneClient;

/**
 * A Jev run end to end through the advancer's real dispatch, only System One faked: snapshot, one wave, the
 * finalising tick. Five candidates fit one request.
 */
final class JevPipelineTest extends DbTestCase
{
    use DrivesRecommendationRuns;
    use SeedsUsers;

    private User $owner;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->owner = $this->user('jev-pipeline@example.test');
        $this->fixtures->seedReadyAiSettingsFor($this->owner, 'jev-latest');
    }

    public function testEveryCandidateIsScoredByItsNoulAndRankedWithoutAReason(): void
    {
        $ids = $this->entryIds($this->fixtures->seedFeedWithEntries($this->owner, 5));
        $nouls = [$ids[0] => 0.2, $ids[1] => 0.91, $ids[2] => 0.55, $ids[3] => 0.0737, $ids[4] => 0.66];
        $this->systemOne()->queueNouls(static fn (int $entryId): float => $nouls[$entryId]);
        $this->storeProfile('Likes Rust and homelab.');

        $run = $this->runToCompletion($this->owner);

        $items = $this->items($run);
        self::assertSame([$ids[1], $ids[4], $ids[2], $ids[0], $ids[3]], array_map(
            static fn (RecommendationItem $item): int => $item->getEntry()->requireId(),
            $items,
        ));
        self::assertSame([910, 660, 550, 200, 74], array_map(
            static fn (RecommendationItem $item): ?int => $item->getScore(),
            $items,
        ));
        self::assertSame(['', '', '', '', ''], array_map(
            static fn (RecommendationItem $item): string => $item->getReason(),
            $items,
        ));
        self::assertSame(RecommendationEngineKind::Scoring, $run->getEngineKind());
        self::assertSame(1, $run->getProgress()->batchesTotal);   // one batch (the LLM: 2)
        self::assertSame([], $this->chat()->calls());
    }

    public function testTheRequestCarriesTheAliasTheStateAndOneQuestionPerCandidate(): void
    {
        $ids = $this->entryIds($this->fixtures->seedFeedWithEntries($this->owner, 5));
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.5);
        $this->storeProfile('Likes Rust and homelab.');

        $this->runToCompletion($this->owner);

        $requests = $this->systemOne()->requests();
        self::assertCount(1, $requests);
        self::assertSame('jev-latest', $requests[0]->model);
        self::assertSame(['profile' => 'Likes Rust and homelab.'], $requests[0]->state);
        self::assertEqualsCanonicalizing(
            array_map(QuestionId::of(...), $ids),
            array_keys($requests[0]->questions),
        );
    }

    public function testTheRunLogKeepsTheCallWithItsReceiptAndTheRunItsCost(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 5);
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.5);
        $this->storeProfile('Likes Rust and homelab.');

        $run = $this->runToCompletion($this->owner);

        $this->entityManager->clear();
        $logs = $this->entityManager->getRepository(RecommendationRunLog::class)
            ->findBy(['run' => $run->requireId()], ['id' => 'ASC']);
        self::assertCount(1, $logs);
        $log = $logs[0];
        self::assertSame(CallPhase::Batch, $log->getPhase());
        self::assertSame(1, $log->getBatchNumber());
        self::assertSame(CallVerdict::Usable, $log->getVerdict());
        self::assertSame(StubSystemOneClient::REQUEST_ID, $log->getRequestId());
        self::assertSame(StubSystemOneClient::ANSWERING_MODEL, $log->getAnsweringModel());
        self::assertSame(StubSystemOneClient::COST_NANO_CREDITS, $log->getCostNanoCredits());
        self::assertStringContainsString('"question"', $log->getRequestBody());
        $fresh = $this->runs()->find($run->requireId());
        self::assertNotNull($fresh);
        self::assertSame(StubSystemOneClient::COST_NANO_CREDITS, $fresh->getCostNanoCredits());
        self::assertSame(StubSystemOneClient::INPUT_TOKENS, $fresh->getPromptTokens());
    }

    /** With "show score and reasons" on, a Jev pick carries its score and an empty reason. */
    public function testTheForYouPageShowsTheScoreWithoutAReason(): void
    {
        $this->fixtures->showScoreAndReasonsEnabledSettings($this->owner);
        $ids = $this->entryIds($this->fixtures->seedFeedWithEntries($this->owner, 5));
        $this->systemOne()->queueNouls(static fn (int $entryId): float => $entryId === $ids[2] ? 0.97 : 0.1);
        $this->storeProfile('Likes Rust and homelab.');
        $this->runToCompletion($this->owner);

        /** @var ForYouFeed $feed */
        $feed = self::getContainer()->get(ForYouFeed::class);
        $page = RecommendationFeedJson::page($feed->page(new ForYouFeedQuery($this->owner)));

        self::assertSame($ids[2], $page['entries'][0]['id']);
        self::assertSame(970, $page['entries'][0]['recommendationScore']);
        self::assertSame('', $page['entries'][0]['recommendationReason']);
    }

    public function testTheGuidanceRidesBesideTheProfile(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 5);
        $this->fixtures->guidanceSettings($this->owner, 'More self-hosting.');
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.5);
        $this->storeProfile('Likes Rust and homelab.');

        $this->runToCompletion($this->owner);

        self::assertSame(
            ['profile' => 'Likes Rust and homelab.', 'guidance' => 'More self-hosting.'],
            $this->systemOne()->requests()[0]->state,
        );
    }

    /** Guidance alone is not enough: a profile run that finds no history leaves the run without a profile. */
    public function testWithoutAProfileTheRunFailsEvenWithGuidance(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 5);
        $this->fixtures->guidanceSettings($this->owner, 'More self-hosting.');
        $profileConnection = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'qwen3-14b');
        $this->fixtures->chooseProfileConnection($this->owner, $profileConnection);
        $this->waitForTheProfileRun();

        $failed = $this->tickUntilDone($this->owner);

        self::assertSame('failed', $failed->getStatus()->value);
        self::assertSame(JevRecommendationEngine::NO_PROFILE, $failed->getError());
        self::assertSame([], $this->chat()->calls());
        self::assertSame([], $this->systemOne()->requests());
    }

    /** Jev builds no profile itself: with no profile connection chosen, the profile run fails, and the run with it. */
    public function testWithoutAProfileConnectionTheWaitingRunFailsWithTheProfileRunsError(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 5);
        $this->waitForTheProfileRun();

        $failed = $this->tickUntilDone($this->owner);

        self::assertSame(\sprintf(SnapshotPhase::PROFILE_FAILED, ProfileConnections::MISSING), $failed->getError());
        self::assertSame([], $this->systemOne()->requests());
    }

    private function waitForTheProfileRun(): void
    {
        $this->starter()->start($this->owner);
        $this->advancer()->advance($this->owner);
        self::assertSame('pending', $this->runs()->findLatestForUser($this->owner)?->getStatus()->value);
        $this->tickTheProfileRun($this->owner);
    }

    private function storeProfile(string $profileText): void
    {
        $this->fixtures->storeProfile($this->owner, $profileText);
    }

    /**
     * @param list<Entry> $entries
     *
     * @return list<int>
     */
    private function entryIds(array $entries): array
    {
        return array_map(static fn (Entry $entry): int => $entry->requireId(), $entries);
    }
}
