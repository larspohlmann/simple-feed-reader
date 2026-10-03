<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Entity\Entry;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationPoolLimits;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationSettings;
use App\Entity\RecommendationSettingsValues;
use App\Entity\User;
use App\Enum\RecommendationBatchSize;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Tests\DbTestCase;
use App\Tests\Support\DrivesRecommendationRuns;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The phases end to end through the advancer's real dispatch, only the provider faked. RecommendationRunAdvancerTest
 * drives each tick on its own; this proves the phases hand over to each other.
 */
final class RecommendationPipelineTest extends DbTestCase
{
    use DrivesRecommendationRuns;

    private User $user;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('pipeline@example.test');
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
    }

    /** A two-batch plan: one call per batch, then one consolidation call, on the profile the run froze. */
    public function testRunScoresThenConsolidates(): void
    {
        $entries = $this->seedTwoBatchCandidates();
        $firstId = $this->idOf($entries[0]);

        $this->storeProfile('Likes Rust.');
        // One reply per batch: the plan has two, and each covers every known
        // id so it answers whichever ten-entry slice the packer put in it.
        $this->queueBatchReplyScoringEveryEntry($entries, 800);
        $this->queueBatchReplyScoringEveryEntry($entries, 800);
        $this->queueConsolidationReply([
            ['id' => $firstId, 'score' => 900, 'reason' => 'On Rust.'],
        ]);

        $run = $this->runToCompletion($this->user);

        $items = $this->items($run);
        self::assertNotEmpty($items);
        // The consolidation reply's own pick outranks every other survivor,
        // which kept its plain batch score of 800 with an empty reason.
        self::assertSame($firstId, $this->entryIdOf($items[0]));
        self::assertSame('On Rust.', $items[0]->getReason()); // reason came from consolidation
        self::assertSame(900, $items[0]->getScore());         // score is the consolidation score
        self::assertSame('Likes Rust.', $this->runProfileTextFor($run));
        self::assertSame(
            ['recommendations', 'recommendations', 'recommendations'],
            array_column($this->chat()->calls(), 'responseSchemaName'),
        );
    }

    /** A single-batch run still spends a consolidation call, and that call supplies the reason the reader sees. */
    public function testConsolidationRunsEvenWhenThereIsOneBatch(): void
    {
        $entries = $this->seedSingleBatchCandidates();
        $firstId = $this->idOf($entries[0]);

        $this->storeProfile('x');
        $this->queueBatchReplyScoringEveryEntry($entries, 700);
        $this->queueConsolidationReply([
            ['id' => $firstId, 'score' => 700, 'reason' => 'y'],
        ]);

        $run = $this->runToCompletion($this->user);

        $items = $this->items($run);
        self::assertNotEmpty($items);
        self::assertSame('y', $items[0]->getReason());
        self::assertSame(
            ['recommendations', 'recommendations'],
            array_column($this->chat()->calls(), 'responseSchemaName'),
        );
    }

    /** Without a stored profile the run still completes, with an empty PROFILE block and no profile frozen on it. */
    public function testWithoutAStoredProfileTheRunScoresWithoutOne(): void
    {
        $entries = $this->seedSingleBatchCandidates();
        $firstId = $this->idOf($entries[0]);

        $this->queueBatchReplyScoringEveryEntry($entries, 600);
        $this->queueConsolidationReply([
            ['id' => $firstId, 'score' => 600, 'reason' => 'z'],
        ]);

        $run = $this->runToCompletion($this->user);

        self::assertNotEmpty($this->items($run)); // the run still completes
        self::assertNull($this->runProfileTextFor($run));       // no profile frozen on the run
    }

    /**
     * An unusable consolidation spends every retry, then degrades to the undeduped batch-score pool: the run
     * completes with empty reasons, in the batch phase's order and scores.
     */
    public function testConsolidationFailureDegradesToBatchOrderEmptyReasons(): void
    {
        $entries = $this->seedSingleBatchCandidates();

        $this->storeProfile('x');
        $this->queueBatchReplyScoringEveryEntry($entries, 500);
        for ($attempt = 0; $attempt < RecommendationRun::MAX_ATTEMPTS; $attempt++) {
            $this->chat()->queueContent('not json');
        }

        $run = $this->runToCompletion($this->user);

        $items = $this->items($run);
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

        // Five candidates always pack into one batch: the packer splits only once a batch holds MINIMUM_BATCH_SIZE.
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
        $this->entityManager->flush();

        // A connection ceiling of 10 caps each batch at 10, so the 20 candidates pack into exactly two batches.
        $this->fixtures->capBatchesAt($this->user, 10);

        $settings = new RecommendationSettings($this->user);
        $settings->update(new RecommendationSettingsValues(
            guidancePrompt: null,
            favoritesCap: RecommendationSettings::DEFAULT_FAVORITES_CAP,
            poolLimits: new RecommendationPoolLimits(
                $entryCount,
                RecommendationSettings::DEFAULT_LOOKBACK_DAYS,
                RecommendationSettings::DEFAULT_PICKS_LIMIT,
            ),
            contextWindow: 200000,
            batchSize: RecommendationBatchSize::Medium,
            debugEnabled: false,
        ));
        $this->entityManager->persist($settings);
        $this->entityManager->flush();

        return $entries;
    }

    private function storeProfile(string $profileText): void
    {
        $this->fixtures->storeProfile($this->user, $profileText);
    }

    /**
     * @param list<Entry> $entries
     */
    private function queueBatchReplyScoringEveryEntry(array $entries, int $score): void
    {
        $this->chat()->queueContent(json_encode([
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
        $this->chat()->queueContent(json_encode(
            ['recommendations' => $recommendations, 'duplicates' => []],
            \JSON_THROW_ON_ERROR,
        ));
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

    private function runProfileTextFor(RecommendationRun $run): ?string
    {
        $this->entityManager->clear();
        $fresh = $this->entityManager->getRepository(RecommendationRun::class)->find($run->getId());
        self::assertNotNull($fresh);

        return $fresh->getProfileText();
    }
}
