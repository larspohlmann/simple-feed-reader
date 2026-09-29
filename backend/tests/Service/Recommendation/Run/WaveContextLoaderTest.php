<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Entity\Entry;
use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Prompt\RecommendationCandidateLoader;
use App\Service\Recommendation\Prompt\RecommendationHistoryLoader;
use App\Service\Recommendation\Run\Model\WaveBatchModel;
use App\Service\Recommendation\Run\WaveContextLoader;
use App\Tests\DbTestCase;
use App\Tests\Support\BuildsTickContexts;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;

final class WaveContextLoaderTest extends DbTestCase
{
    use BuildsTickContexts;
    use SeedsUsers;

    private User $owner;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->em, $cipher);
        $this->owner = $this->user('wave-context@example.test');
        $this->fixtures->seedReadyAiSettings($this->owner);
    }

    public function testTheWaveTakesTheNextBatchesFramedByTheWholePool(): void
    {
        $ids = $this->entryIds(6);
        $run = $this->runWithPlan([[$ids[0], $ids[1]], [$ids[2], $ids[3]], [$ids[4], $ids[5]]]);
        $run->recordProfile('Likes Rust.');
        $run->recordBatchWinners([]);
        $this->em->flush();

        $wave = $this->loader()->load($this->tick($run), 2);

        self::assertSame([1, 2], array_map(static fn (WaveBatchModel $batch): int => $batch->index, $wave->batches));
        self::assertSame([$ids[2], $ids[3]], $wave->batches[0]->ids);
        self::assertEqualsCanonicalizing([$ids[4], $ids[5]], $wave->batches[1]->validIds());
        self::assertSame(6, $wave->poolSummary?->total);
        self::assertSame('Likes Rust.', $wave->prompt->profile);
        self::assertSame($run, $wave->tick->run);
    }

    public function testAnEntryPrunedSinceTheSnapshotKeepsItsIdButLosesItsLineAndItsPlaceInThePool(): void
    {
        $ids = $this->entryIds(2);
        $run = $this->runWithPlan([[$ids[0], $ids[1]]]);
        $pruned = $this->em->getRepository(Entry::class)->find($ids[1]);
        self::assertNotNull($pruned);
        $this->em->remove($pruned);
        $this->em->flush();

        $wave = $this->loader()->load($this->tick($run), 1);

        self::assertSame([$ids[0], $ids[1]], $wave->batches[0]->ids);
        self::assertSame([$ids[0]], $wave->batches[0]->validIds());
        self::assertSame(1, $wave->poolSummary?->total);
    }

    /** @return list<int> */
    private function entryIds(int $count): array
    {
        return array_map(
            static fn (Entry $entry): int => $entry->requireId(),
            $this->fixtures->seedFeedWithEntries($this->owner, $count),
        );
    }

    /** @param list<list<int>> $plan */
    private function runWithPlan(array $plan): RecommendationRun
    {
        $run = $this->fixtures->createRun($this->owner);
        $run->snapshot($plan);
        $this->em->flush();

        return $run;
    }

    private function loader(): WaveContextLoader
    {
        /** @var RecommendationCandidateLoader $candidates */
        $candidates = self::getContainer()->get(RecommendationCandidateLoader::class);
        /** @var RecommendationHistoryLoader $history */
        $history = self::getContainer()->get(RecommendationHistoryLoader::class);

        return new WaveContextLoader($candidates, $history);
    }
}
