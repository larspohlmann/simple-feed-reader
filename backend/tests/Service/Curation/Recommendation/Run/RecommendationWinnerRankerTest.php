<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Run;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\LinkCardContent;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\LinkCardRepository;
use App\Service\Curation\Recommendation\Run\RecommendationWinnerRanker;
use App\Tests\DbTestCase;
use App\Tests\Support\SharedLinks;

final class RecommendationWinnerRankerTest extends DbTestCase
{
    private RecommendationWinnerRanker $ranker;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('ranker@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->user);
        $this->entityManager->flush();

        $ranker = self::getContainer()->get(RecommendationWinnerRanker::class);
        self::assertInstanceOf(RecommendationWinnerRanker::class, $ranker);
        $this->ranker = $ranker;
    }

    public function testRankedFlattensAllBatchesAndSortsByScoreDescending(): void
    {
        $ranked = $this->ranker->ranked($this->user->requireId(), [
            [['id' => 1, 'score' => 40, 'reason' => 'a'], ['id' => 2, 'score' => 90, 'reason' => 'b']],
            [['id' => 3, 'score' => 70, 'reason' => 'c']],
        ]);

        self::assertSame([2, 3, 1], array_column($ranked, 'id'));
        self::assertSame([90, 70, 40], array_column($ranked, 'score'));
        self::assertSame(['b', 'c', 'a'], array_column($ranked, 'reason'));
    }

    public function testTiedScoresKeepBatchOrderWhichIsSnapshotRecencyOrder(): void
    {
        $ranked = $this->ranker->ranked($this->user->requireId(), [
            [
                ['id' => 1, 'score' => 50, 'reason' => 'first batch, tied'],
                ['id' => 5, 'score' => 99, 'reason' => 'first batch, outright winner'],
            ],
            [
                ['id' => 6, 'score' => 1, 'reason' => 'second batch, outright loser'],
                ['id' => 2, 'score' => 50, 'reason' => 'second batch, tied'],
            ],
            [
                ['id' => 3, 'score' => 50, 'reason' => 'third batch, tied'],
            ],
        ]);

        self::assertSame([5, 1, 2, 3, 6], array_column($ranked, 'id'));
    }

    public function testAZeroScoredWinnerSortsLast(): void
    {
        $ranked = $this->ranker->ranked($this->user->requireId(), [
            [['id' => 1, 'score' => 0, 'reason' => 'legacy row'], ['id' => 2, 'score' => 10, 'reason' => 'scored']],
        ]);

        self::assertSame([2, 1], array_column($ranked, 'id'));
    }

    public function testEmptyBatchesRankToAnEmptyPool(): void
    {
        self::assertSame([], $this->ranker->ranked($this->user->requireId(), [[], []]));
    }

    public function testTwoPostsWhoseFirstCardsShareOneLandingRankToOneWinner(): void
    {
        $feed = new Feed('https://bsky.app/profile/alice.bsky.social/rss');
        $this->entityManager->persist($feed);
        $this->entityManager->persist(
            new Subscription($this->user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );
        $first = $this->postSharing($feed, 'first', 'https://news.example.com/story');
        $second = $this->postSharing($feed, 'second', 'https://news.example.com/story');

        $ranked = $this->ranker->ranked($this->user->requireId(), [[
            ['id' => $first->requireId(), 'score' => 60, 'reason' => 'worse'],
            ['id' => $second->requireId(), 'score' => 80, 'reason' => 'better'],
        ]]);

        self::assertSame([$second->requireId()], array_column($ranked, 'id'));
    }

    private function postSharing(Feed $feed, string $guid, string $url): Entry
    {
        $moment = new \DateTimeImmutable('2026-07-10T00:00:00Z');
        $post = new Entry($feed, $guid, 'https://bsky.app/' . $guid, $guid, $moment, $moment);
        $this->entityManager->persist($post);
        $this->entityManager->flush();

        $cards = self::getContainer()->get(LinkCardRepository::class);
        self::assertInstanceOf(LinkCardRepository::class, $cards);
        $content = new LinkCardContent($url, 'Story', null, null, 'Example');
        $cards->append($post, [SharedLinks::of($this->entityManager, $content)]);
        $this->entityManager->flush();

        return $post;
    }
}
