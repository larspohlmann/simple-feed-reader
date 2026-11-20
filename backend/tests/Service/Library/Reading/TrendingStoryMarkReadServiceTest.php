<?php

declare(strict_types=1);

namespace App\Tests\Service\Library\Reading;

use App\Entity\EntryState;
use App\Enum\TrendingWindow;
use App\Repository\TrendingStoryListQuery;
use App\Service\Library\Reading\TrendingStoryMarkReadService;
use App\Tests\DbTestCase;
use App\Tests\Support\LemmyPostCopies;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\TrendingFixtures;

final class TrendingStoryMarkReadServiceTest extends DbTestCase
{
    use SeedsUsers;

    public function testMarkingAStoryLeavesNoCopyOfAMemberUnread(): void
    {
        $reader = $this->user('story-copies@example.com');
        $copies = LemmyPostCopies::persistPair($this->entityManager, $reader);
        $story = new TrendingFixtures($this->entityManager, $reader)
            ->story(TrendingWindow::Day, '2026-11-14 09:00:00', $copies->localCopy);

        $this->service()->mark(
            new TrendingStoryListQuery($reader->requireId(), $story->requireId()),
            new \DateTimeImmutable('2026-11-15T00:00:00Z'),
        );

        $this->entityManager->clear();
        $copyState = $this->entityManager->getRepository(EntryState::class)
            ->findOneForUserEntry($reader->requireId(), $copies->allCopy->requireId());
        self::assertTrue($copyState?->isHidden());
    }

    private function service(): TrendingStoryMarkReadService
    {
        $service = self::getContainer()->get(TrendingStoryMarkReadService::class);
        self::assertInstanceOf(TrendingStoryMarkReadService::class, $service);

        return $service;
    }
}
