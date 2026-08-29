<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\ForYouFeedQuery;
use App\Repository\RecommendationItemRepository;
use App\Service\Recommendation\RecommendationFeedPager;
use App\Tests\DbTestCase;

final class RecommendationFeedPagerTest extends DbTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('pager@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);

        $feed = new Feed('https://example.com/feed.xml');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($this->user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));

        $run = new RecommendationRun($this->user, new \DateTimeImmutable('2026-08-07T09:00:00Z'));
        $run->snapshot([[1]]);
        $run->complete(new \DateTimeImmutable('2026-08-07T09:05:00Z'));
        $this->em->persist($run);

        foreach (['a', 'b'] as $position => $guid) {
            $createdAt = new \DateTimeImmutable('2026-07-01T00:00:00Z');
            $entry = new Entry($feed, $guid, null, 'Title ' . $guid, $createdAt, $createdAt);
            $this->em->persist($entry);
            $this->em->persist(new RecommendationItem($run, $entry, $position + 1, 'reason ' . $guid));
        }
        $this->em->flush();
    }

    public function testAMalformedCursorYieldsTheFirstPageInsteadOfAnError(): void
    {
        $page = $this->pager()->page(new ForYouFeedQuery($this->user, 'not-a-cursor', 50));

        self::assertCount(2, $page->rows);
        self::assertSame('a', $page->rows[0]->row->entry->getGuid());
    }

    public function testTheLimitIsClampedToAtLeastOne(): void
    {
        $page = $this->pager()->page(new ForYouFeedQuery($this->user, null, 0));

        self::assertCount(1, $page->rows);
    }

    private function pager(): RecommendationFeedPager
    {
        $repository = $this->em->getRepository(RecommendationItem::class);
        self::assertInstanceOf(RecommendationItemRepository::class, $repository);

        return new RecommendationFeedPager($repository);
    }
}
