<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\StoryAi;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Curation\Ai\Crypto\ApiKeyCipher;
use App\Service\Curation\StoryAi\StoryAiSweep;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;
use Symfony\Component\Clock\MockClock;

final class StoryAiSweepTest extends DbTestCase
{
    use SeedsUsers;

    private MockClock $clock;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2031-05-14 10:00:00', 'UTC');
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
    }

    public function testATaskRunsEachDueStoryOfAReaderWithAConnection(): void
    {
        $reader = $this->activeReader('sweep-due@example.test');
        $task = new RecordingStoryAiTask($this->connectionOf($reader), [$reader->requireId() => [3, 5]]);

        $report = $this->sweep($task)->runDue();

        self::assertSame([[$reader->requireId(), 3], [$reader->requireId(), 5]], $task->runs);
        self::assertSame(['usable' => 2, 'unusable' => 0, 'failed' => 0], $report->toLogContext());
    }

    public function testATaskWithoutAConnectionSkipsTheUser(): void
    {
        $reader = $this->activeReader('sweep-no-connection@example.test');
        $withoutConnection = new RecordingStoryAiTask(null, [$reader->requireId() => [3]]);
        $withConnection = new RecordingStoryAiTask($this->connectionOf($reader), [$reader->requireId() => [4]]);

        $this->sweep($withoutConnection, $withConnection)->runDue();

        self::assertSame([], $withoutConnection->dueAskedFor);
        self::assertSame([], $withoutConnection->runs);
        self::assertSame([[$reader->requireId(), 4]], $withConnection->runs);
    }

    public function testARateLimitStopsOnlyThatUser(): void
    {
        $first = $this->activeReader('sweep-limited@example.test');
        $second = $this->activeReader('sweep-unlimited@example.test');
        $this->connectionOf($second);
        $task = new RecordingStoryAiTask(
            $this->connectionOf($first),
            [$first->requireId() => [3, 5], $second->requireId() => [7]],
            [$first->requireId() => 3],
        );

        $this->sweep($task)->runDue();

        self::assertSame([[$first->requireId(), 3], [$second->requireId(), 7]], $task->runs);
    }

    public function testNothingStartsAfterTheDeadline(): void
    {
        $reader = $this->activeReader('sweep-deadline@example.test');
        $task = new RecordingStoryAiTask($this->connectionOf($reader), [$reader->requireId() => [3]]);

        $report = $this->sweep($task)->runDueBefore($this->clock->now()->modify('-1 second'));

        self::assertSame([], $task->runs);
        self::assertSame(['usable' => 0, 'unusable' => 0, 'failed' => 0], $report->toLogContext());
    }

    public function testEveryTaggedTaskRuns(): void
    {
        $reader = $this->activeReader('sweep-every-task@example.test');
        $connection = $this->connectionOf($reader);
        $first = new RecordingStoryAiTask($connection, [$reader->requireId() => [3]]);
        $second = new RecordingStoryAiTask($connection, [$reader->requireId() => [4]]);

        $this->sweep($first, $second)->runDue();

        self::assertSame([[$reader->requireId(), 3]], $first->runs);
        self::assertSame([[$reader->requireId(), 4]], $second->runs);
    }

    public function testAReaderAwayLongerThanTheActiveDaysIsSkipped(): void
    {
        $reader = $this->user('sweep-away@example.test');
        $reader->setLastLoginAt($this->clock->now()->modify(\sprintf('-%d days -1 minute', StoryAiSweep::ACTIVE_DAYS)));
        $this->entityManager->flush();
        $task = new RecordingStoryAiTask($this->connectionOf($reader), [$reader->requireId() => [3]]);

        $this->sweep($task)->runDue();

        self::assertSame([], $task->runs);
    }

    private function activeReader(string $email): User
    {
        $reader = $this->user($email);
        $reader->setLastLoginAt($this->clock->now()->modify('-1 day'));
        $this->entityManager->flush();

        return $reader;
    }

    private function connectionOf(User $reader): AiProviderSettings
    {
        return $this->fixtures->seedReadyAiSettingsFor($reader, 'qwen3-14b');
    }

    private function sweep(RecordingStoryAiTask ...$tasks): StoryAiSweep
    {
        /** @var UserRepository $users */
        $users = self::getContainer()->get(UserRepository::class);

        return new StoryAiSweep($tasks, $users, new NaiveUtcClock($this->clock));
    }
}
