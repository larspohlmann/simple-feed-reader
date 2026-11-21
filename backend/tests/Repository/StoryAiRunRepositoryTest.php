<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\StoryAiRun;
use App\Entity\User;
use App\Enum\StoryAiTask;
use App\Repository\StoryAiRunRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;

final class StoryAiRunRepositoryTest extends DbTestCase
{
    use SeedsUsers;

    public function testTheNewestRunsAreTheAccountsOwnUpToTheLimit(): void
    {
        $owner = $this->user('story-runs-newest@example.test');
        $this->storyRun($owner);
        $second = $this->storyRun($owner);
        $third = $this->storyRun($owner);
        $this->storyRun($this->user('story-runs-newest-other@example.test'));

        self::assertSame([$third, $second], $this->storyAiRuns()->findNewestForUser($owner, 2));
    }

    public function testTheNewestIdsStopAtTheLimit(): void
    {
        $owner = $this->user('story-runs-ids@example.test');
        $this->storyRun($owner);
        $second = $this->storyRun($owner);
        $third = $this->storyRun($owner);

        self::assertSame(
            [$third->requireId(), $second->requireId()],
            $this->storyAiRuns()->findNewestIdsForUser($owner, 2),
        );
    }

    private function storyRun(User $owner): StoryAiRun
    {
        $storyRun = new StoryAiRun($owner, StoryAiTask::Title, new \DateTimeImmutable('2026-10-03 09:00:00'));
        $this->entityManager->persist($storyRun);
        $this->entityManager->flush();

        return $storyRun;
    }

    private function storyAiRuns(): StoryAiRunRepository
    {
        /** @var StoryAiRunRepository $storyAiRuns */
        $storyAiRuns = $this->entityManager->getRepository(StoryAiRun::class);

        return $storyAiRuns;
    }
}
