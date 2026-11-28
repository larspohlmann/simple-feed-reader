<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\StoryAi;

use App\Entity\RecommendationRunLog;
use App\Entity\StoryAiRun;
use App\Entity\User;
use App\Enum\StoryAiTask;
use App\Service\Curation\Recommendation\Run\Support\RunLogRetention;
use App\Service\Curation\StoryAi\Pass\StoryAiPass;
use App\Service\Curation\StoryAi\StoryAiRuns;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;

final class StoryAiRunsTest extends DbTestCase
{
    use SeedsUsers;

    private User $owner;
    private StoryAiPass $pass;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->user('story-ai-runs@example.test');
        $this->pass = new StoryAiPass(
            $this->owner,
            RecommendationRunFixtures::fromContainer(self::getContainer())
                ->seedReadyAiSettingsFor($this->owner, 'qwen3-14b'),
            StoryAiTask::Title,
            new \DateTimeImmutable('2031-05-14 10:00:00'),
        );
    }

    public function testOpeningARunKeepsEveryRunAndTrimsLogsToTheNewestRuns(): void
    {
        $oldest = $this->runWithOneLogRow($this->owner);
        for ($index = 1; $index < RunLogRetention::RUNS; $index++) {
            $this->runWithOneLogRow($this->owner);
        }
        $otherAccountsRun = $this->runWithOneLogRow($this->user('story-ai-runs-other@example.test'));

        $opened = $this->runs()->openFor($this->pass);

        $runIds = $this->runIdsOf($this->owner);
        self::assertCount(RunLogRetention::RUNS + 1, $runIds);
        self::assertContains($oldest, $runIds);
        self::assertContains($opened->requireId(), $runIds);
        self::assertSame(0, $this->logRowsOf($oldest));
        self::assertSame(1, $this->logRowsOf($otherAccountsRun));
        self::assertSame(RunLogRetention::RUNS - 1, $this->logRowsOfAccount($this->owner));
    }

    public function testASecondCallOfThePassReusesItsRun(): void
    {
        $first = $this->runs()->openFor($this->pass);

        self::assertSame($first, $this->runs()->openFor($this->pass));
        self::assertCount(1, $this->runIdsOf($this->owner));
    }

    public function testOpeningARunStampsTheConnectionsProviderAndModel(): void
    {
        $openedId = $this->runs()->openFor($this->pass)->requireId();

        $stamp = $this->entityManager->createQueryBuilder()
            ->select('run.providerUsage.providerHost AS providerHost', 'run.providerUsage.model AS model')
            ->from(StoryAiRun::class, 'run')
            ->where('run.id = :id')
            ->setParameter('id', $openedId)
            ->getQuery()
            ->getSingleResult();

        self::assertSame(['providerHost' => 'api.example.test', 'model' => 'qwen3-14b'], $stamp);
    }

    private function runWithOneLogRow(User $owner): int
    {
        $run = new StoryAiRun($owner, StoryAiTask::Title, new \DateTimeImmutable('2031-05-13 10:00:00'));
        $this->entityManager->persist($run);
        $this->entityManager->persist(
            RecommendationRunLog::forStoryAiRun($run, '{}', new \DateTimeImmutable('2031-05-13 10:00:00')),
        );
        $this->entityManager->flush();

        return $run->requireId();
    }

    /** @return list<int> */
    private function runIdsOf(User $owner): array
    {
        /** @var list<int|string> $runIds */
        $runIds = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT id FROM story_ai_run WHERE user_id = ?',
            [$owner->requireId()],
        );

        return array_map(static fn (int|string $runId): int => (int) $runId, $runIds);
    }

    private function logRowsOf(int $runId): int
    {
        return $this->rowCount(
            'SELECT COUNT(*) FROM recommendation_run_log WHERE story_ai_run_id = ?',
            [$runId],
        );
    }

    private function logRowsOfAccount(User $owner): int
    {
        return $this->rowCount(
            'SELECT COUNT(*) FROM recommendation_run_log log JOIN story_ai_run run ON run.id = log.story_ai_run_id'
            . ' WHERE run.user_id = ?',
            [$owner->requireId()],
        );
    }

    /** @param list<int> $parameters */
    private function rowCount(string $query, array $parameters): int
    {
        $count = $this->entityManager->getConnection()->fetchOne($query, $parameters);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function runs(): StoryAiRuns
    {
        /** @var StoryAiRuns $runs */
        $runs = self::getContainer()->get(StoryAiRuns::class);

        return $runs;
    }
}
