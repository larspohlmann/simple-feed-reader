<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\Entry;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationSettings;
use App\Entity\User;
use App\Repository\RecommendationRunRepository;
use App\Repository\RecommendationSettingsRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\EffectiveRecommendationSettings;
use App\Service\Recommendation\RecommendationRunAdvancer;
use App\Service\Recommendation\RecommendationRunStarter;
use App\Service\Recommendation\RecommendationSettingsValues;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\StubChatClient;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Task 12 (#493): the whole pipeline driven end to end -- snapshot, distill,
 * score-only batches, consolidate, finalize -- against the real container's
 * repository, entity manager and settings resolver, with only the provider
 * faked (StubChatClient, the same seam every other recommendation test in
 * this tree uses).
 *
 * RecommendationRunAdvancerTest already pins each tick's own behaviour in
 * isolation, one phase transition at a time; that is direct-invocation
 * coverage of the wiring, not proof the wiring holds together. This file is
 * the functional case CLAUDE.md asks for instead: it proves the phases
 * actually chain into each other through the advancer's real dispatch, not
 * through a test that calls each tick method by hand.
 */
final class RecommendationPipelineTest extends DbTestCase
{
    private const int MAX_TICKS = 20;

    private User $user;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->em, $hasher))->create('pipeline@example.test');
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->em, $cipher);
    }

    /**
     * The full happy path across a two-batch plan: one distillation call,
     * one call per batch, then one consolidation call that re-scores and
     * re-reasons the pool -- the final RecommendationItem carries that
     * consolidation reply's score and reason, not the batch's own, and the
     * distilled profile survives on the account's settings past this run.
     */
    public function testRunDistillsThenScoresThenConsolidates(): void
    {
        $entries = $this->seedTwoBatchCandidates();
        $firstId = $this->idOf($entries[0]);

        $this->queueDistillReply('Likes Rust.');
        // One reply per batch: the plan has two, and each covers every known
        // id so it answers whichever ten-entry slice the packer put in it.
        $this->queueBatchReplyScoringEveryEntry($entries, 800);
        $this->queueBatchReplyScoringEveryEntry($entries, 800);
        $this->queueConsolidationReply([
            ['id' => $firstId, 'score' => 900, 'reason' => 'On Rust.'],
        ]);

        $run = $this->runToCompletion();

        $items = $this->recommendationItems($run);
        self::assertNotEmpty($items);
        // The consolidation reply's own pick outranks every other survivor,
        // which kept its plain batch score of 800 with an empty reason.
        self::assertSame($firstId, $this->entryIdOf($items[0]));
        self::assertSame('On Rust.', $items[0]->getReason()); // reason came from consolidation
        self::assertSame(900, $items[0]->getScore());         // score is the consolidation score
        self::assertSame('Likes Rust.', $this->storedProfileText()); // cached on settings
        self::assertSame(
            ['profile', 'recommendations', 'recommendations', 'recommendations'],
            array_column($this->stubChatClient()->calls(), 'responseSchemaName'),
        );
    }

    /**
     * #493's central behavioural change: a single-batch run is no longer a
     * shortcut straight to the ranked pool. It still spends a consolidation
     * call, and that call is still what supplies the reason the reader sees.
     */
    public function testConsolidationRunsEvenWhenThereIsOneBatch(): void
    {
        $entries = $this->seedSingleBatchCandidates();
        $firstId = $this->idOf($entries[0]);

        $this->queueDistillReply('x');
        $this->queueBatchReplyScoringEveryEntry($entries, 700);
        $this->queueConsolidationReply([
            ['id' => $firstId, 'score' => 700, 'reason' => 'y'],
        ]);

        $run = $this->runToCompletion();

        $items = $this->recommendationItems($run);
        self::assertNotEmpty($items);
        self::assertSame('y', $items[0]->getReason());
        self::assertSame(
            ['profile', 'recommendations', 'recommendations'],
            array_column($this->stubChatClient()->calls(), 'responseSchemaName'),
        );
    }

    /**
     * A distillation call that never becomes usable spends every retry and
     * then degrades to no profile at all (#493): the run still reaches every
     * later phase and completes, just with an empty PROFILE block on every
     * prompt from here on, and no profile frozen on the run itself.
     */
    public function testDistillationFailureDegradesToNoProfileBatches(): void
    {
        $entries = $this->seedSingleBatchCandidates();
        $firstId = $this->idOf($entries[0]);

        for ($attempt = 0; $attempt < RecommendationRun::MAX_ATTEMPTS; $attempt++) {
            $this->stubChatClient()->queueContent('not json');
        }
        $this->queueBatchReplyScoringEveryEntry($entries, 600);
        $this->queueConsolidationReply([
            ['id' => $firstId, 'score' => 600, 'reason' => 'z'],
        ]);

        $run = $this->runToCompletion();

        self::assertNotEmpty($this->recommendationItems($run)); // the run still completes
        self::assertNull($this->runProfileTextFor($run));       // no profile frozen on the run
        self::assertNull($this->storedProfileText());           // nothing was cached on settings either
    }

    /**
     * A consolidation call that never becomes usable spends every retry and
     * then degrades to the plain batch-score pool, undeduped and unreasoned
     * (#493): the run still completes, but the final list's reason is empty
     * and its order and score are exactly the batch phase's own.
     */
    public function testConsolidationFailureDegradesToBatchOrderEmptyReasons(): void
    {
        $entries = $this->seedSingleBatchCandidates();

        $this->queueDistillReply('x');
        $this->queueBatchReplyScoringEveryEntry($entries, 500);
        for ($attempt = 0; $attempt < RecommendationRun::MAX_ATTEMPTS; $attempt++) {
            $this->stubChatClient()->queueContent('not json');
        }

        $run = $this->runToCompletion();

        $items = $this->recommendationItems($run);
        self::assertNotEmpty($items);
        self::assertSame('', $items[0]->getReason()); // empty-string reason on degrade
        self::assertSame(500, $items[0]->getScore());  // the undeduped batch score, not a consolidation one
    }

    /**
     * @return list<Entry>
     */
    private function seedSingleBatchCandidates(): array
    {
        $this->fixtures->seedReadyAiSettings($this->user);

        // Five candidates always pack into one batch (the packer only splits
        // once a batch holds MINIMUM_BATCH_SIZE entries), regardless of the
        // context window -- see RecommendationRunAdvancerTest's own fixture
        // for the same reasoning.
        return $this->fixtures->seedFeedWithEntries($this->user, 5);
    }

    /**
     * @return list<Entry>
     */
    private function seedTwoBatchCandidates(): array
    {
        $this->fixtures->seedReadyAiSettings($this->user);
        $entryCount = 20;

        $summary = str_repeat('Lorem ipsum dolor sit amet consectetur adipiscing elit. ', 5);
        $entries = $this->fixtures->seedFeedWithEntries($this->user, $entryCount);
        foreach ($entries as $entry) {
            $entry->setSummary($summary);
        }
        $this->em->flush();

        // The batchCount expert override forces the packer to split into
        // exactly two batches, regardless of the context window -- the same
        // technique RecommendationRunAdvancerTest's seedForcedBatchCountFixture
        // uses.
        $settings = new RecommendationSettings($this->user);
        $settings->update(new RecommendationSettingsValues(
            guidancePrompt: null,
            favoritesCap: EffectiveRecommendationSettings::DEFAULT_FAVORITES_CAP,
            keptCap: EffectiveRecommendationSettings::DEFAULT_KEPT_CAP,
            viewedCap: EffectiveRecommendationSettings::DEFAULT_VIEWED_CAP,
            candidatePoolSize: $entryCount,
            lookbackDays: EffectiveRecommendationSettings::DEFAULT_LOOKBACK_DAYS,
            picksLimit: EffectiveRecommendationSettings::DEFAULT_PICKS_LIMIT,
            contextWindow: 2500,
            batchCount: 2,
            debugEnabled: false,
        ));
        $this->em->persist($settings);
        $this->em->flush();

        return $entries;
    }

    private function queueDistillReply(string $profile): void
    {
        $this->stubChatClient()->queueContent(json_encode(['profile' => $profile], \JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<Entry> $entries
     */
    private function queueBatchReplyScoringEveryEntry(array $entries, int $score): void
    {
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => array_map(
                fn (Entry $entry): array => ['id' => $this->idOf($entry), 'score' => $score],
                $entries,
            ),
        ], \JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<array{id: int, score: int, reason: string}> $recommendations
     */
    private function queueConsolidationReply(array $recommendations): void
    {
        $this->stubChatClient()->queueContent(json_encode(
            ['recommendations' => $recommendations, 'duplicates' => []],
            \JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Starts a run and drives advance() until nothing is active, exactly the
     * way the poll driver and the worker both drain a run in production --
     * the loop itself is the thing under test, not a shortcut around it.
     */
    private function runToCompletion(): RecommendationRun
    {
        $this->starter()->start($this->user);

        for ($tick = 0; $tick < self::MAX_TICKS; $tick++) {
            if (null === $this->runs()->findActiveForUser($this->user)) {
                break;
            }
            $this->advancer()->advance($this->user);
        }

        self::assertNull(
            $this->runs()->findActiveForUser($this->user),
            'The pipeline did not reach a terminal state within ' . self::MAX_TICKS . ' ticks.',
        );

        $run = $this->runs()->findLatestForUser($this->user);
        self::assertNotNull($run);

        return $run;
    }

    private function idOf(Entry $entry): int
    {
        $id = $entry->getId();
        self::assertNotNull($id);

        return $id;
    }

    private function entryIdOf(RecommendationItem $item): int
    {
        $id = $item->getEntry()->getId();
        self::assertNotNull($id);

        return $id;
    }

    /**
     * @return list<RecommendationItem>
     */
    private function recommendationItems(RecommendationRun $run): array
    {
        $this->em->clear();

        /** @var list<RecommendationItem> $items */
        $items = $this->em->getRepository(RecommendationItem::class)->findBy(['run' => $run], ['position' => 'ASC']);

        return $items;
    }

    private function runProfileTextFor(RecommendationRun $run): ?string
    {
        $this->em->clear();
        $fresh = $this->em->getRepository(RecommendationRun::class)->find($run->getId());
        self::assertNotNull($fresh);

        return $fresh->getProfileText();
    }

    private function storedProfileText(): ?string
    {
        $this->em->clear();
        /** @var RecommendationSettingsRepository $repository */
        $repository = $this->em->getRepository(RecommendationSettings::class);
        $settings = $repository->findForUser($this->user);

        return $settings?->values()->profileText;
    }

    private function runs(): RecommendationRunRepository
    {
        /** @var RecommendationRunRepository $repository */
        $repository = $this->em->getRepository(RecommendationRun::class);

        return $repository;
    }

    private function starter(): RecommendationRunStarter
    {
        /** @var RecommendationRunStarter $starter */
        $starter = self::getContainer()->get(RecommendationRunStarter::class);

        return $starter;
    }

    private function advancer(): RecommendationRunAdvancer
    {
        /** @var RecommendationRunAdvancer $advancer */
        $advancer = self::getContainer()->get(RecommendationRunAdvancer::class);

        return $advancer;
    }

    private function stubChatClient(): StubChatClient
    {
        /** @var StubChatClient $client */
        $client = self::getContainer()->get(StubChatClient::class);

        return $client;
    }
}
