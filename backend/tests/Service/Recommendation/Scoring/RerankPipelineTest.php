<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring;

use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Enum\CallVerdict;
use App\Http\RecommendationFeedJson;
use App\Repository\ForYouFeedQuery;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Feed\ForYouFeed;
use App\Service\Recommendation\Scoring\Factory\RerankQueryFactory;
use App\Tests\DbTestCase;
use App\Tests\Support\DrivesRecommendationRuns;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StubRerankClient;

/** A reranker run end to end through the advancer's real dispatch, only the rerank client faked. */
final class RerankPipelineTest extends DbTestCase
{
    use DrivesRecommendationRuns;
    use SeedsUsers;

    private const string PROFILE = 'Likes Rust and homelab.';

    private User $owner;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->owner = $this->user('rerank-pipeline@example.test');
        $this->fixtures->seedReadyRerankSettings($this->owner);
    }

    /** A hundred documents a request; the second request's article ranks first, so the batches pool by relevance. */
    public function testTheRunRanksEveryBatchByRelevanceScaledToTheRunsScore(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 101);
        $this->rerank()->queueRelevances(static fn (int $entryId): float => 0.5);
        $this->rerank()->queueRelevances(static fn (int $entryId): float => 0.99);
        $this->fixtures->storeProfile($this->owner, self::PROFILE);

        $run = $this->runToCompletion($this->owner);

        $requests = $this->rerank()->requests();
        self::assertCount(2, $requests);
        self::assertCount(100, $requests[0]->documents);
        $items = $this->items($run);
        self::assertSame($requests[1]->entryIds()[0], $items[0]->getEntry()->requireId());
        self::assertSame([990, 500], array_map(
            static fn (RecommendationItem $item): ?int => $item->getScore(),
            \array_slice($items, 0, 2),
        ));
        self::assertSame('', $items[0]->getReason());
        self::assertSame([], $this->systemOne()->requests());
    }

    public function testTheRequestCarriesTheModelTheReaderAsTheQueryAndOneDocumentPerCandidate(): void
    {
        $ids = $this->entryIds($this->fixtures->seedFeedWithEntries($this->owner, 5));
        $this->rerank()->queueRelevances(static fn (int $entryId): float => 0.5);
        $this->fixtures->storeProfile($this->owner, self::PROFILE);

        $this->runToCompletion($this->owner);

        $request = $this->rerank()->requests()[0];
        self::assertSame('cohere/rerank-4-fast', $request->model);
        self::assertSame(RerankQueryFactory::QUESTION . "\nProfile: " . self::PROFILE, $request->query);
        self::assertEqualsCanonicalizing($ids, $request->entryIds());
    }

    /** The stub answers as a dated snapshot of the requested model: the log keeps the model the reply names. */
    public function testTheRunLogKeepsTheCallWithItsReceiptAndTheRunItsCost(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 5);
        $this->rerank()->queueRelevances(static fn (int $entryId): float => 0.5);
        $this->fixtures->storeProfile($this->owner, self::PROFILE);

        $run = $this->runToCompletion($this->owner);

        $logs = $this->logs($run);
        self::assertCount(1, $logs);
        self::assertSame(CallVerdict::Usable, $logs[0]->getVerdict());
        self::assertSame(StubRerankClient::REQUEST_ID, $logs[0]->getRequestId());
        self::assertSame('cohere/rerank-4-fast-20260901', $logs[0]->getAnsweringModel());
        self::assertSame(StubRerankClient::COST_NANO_CREDITS, $logs[0]->getCostNanoCredits());
        self::assertStringContainsString('"documents"', $logs[0]->getRequestBody());
        $fresh = $this->runs()->find($run->requireId());
        self::assertNotNull($fresh);
        self::assertSame(StubRerankClient::COST_NANO_CREDITS, $fresh->getCostNanoCredits());
        self::assertSame(StubRerankClient::TOTAL_TOKENS, $fresh->getPromptTokens());
    }

    /** Raw logits (a local reranker): never clamped into a ranking; retried, then the batch yields nothing. */
    public function testARelevanceOutsideZeroToOneIsNeverRanked(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 5);
        for ($attempt = 0; $attempt < RecommendationRun::MAX_ATTEMPTS; $attempt++) {
            $this->rerank()->queueRelevances(static fn (int $entryId): float => 3.2);
        }
        $this->fixtures->storeProfile($this->owner, self::PROFILE);

        $run = $this->runToCompletion($this->owner);

        self::assertSame('completed', $run->getStatus()->value);
        self::assertSame([], $this->items($run));
        self::assertSame(
            array_fill(0, RecommendationRun::MAX_ATTEMPTS, CallVerdict::Unusable),
            array_map(static fn (RecommendationRunLog $log): ?CallVerdict => $log->getVerdict(), $this->logs($run)),
        );
    }

    /** With "show score and reasons" on, a reranker's pick carries its scaled relevance and an empty reason. */
    public function testTheForYouPageShowsTheScoreWithoutAReason(): void
    {
        $this->fixtures->showScoreAndReasonsEnabledSettings($this->owner);
        $ids = $this->entryIds($this->fixtures->seedFeedWithEntries($this->owner, 5));
        $this->rerank()->queueRelevances(static fn (int $entryId): float => $entryId === $ids[2] ? 0.874 : 0.1);
        $this->fixtures->storeProfile($this->owner, self::PROFILE);
        $this->runToCompletion($this->owner);

        /** @var ForYouFeed $feed */
        $feed = self::getContainer()->get(ForYouFeed::class);
        $page = RecommendationFeedJson::page($feed->page(new ForYouFeedQuery($this->owner)));

        self::assertSame($ids[2], $page['entries'][0]['id']);
        self::assertSame(874, $page['entries'][0]['recommendationScore']);
        self::assertSame('', $page['entries'][0]['recommendationReason']);
    }
}
