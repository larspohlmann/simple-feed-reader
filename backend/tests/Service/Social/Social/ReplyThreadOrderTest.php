<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social;

use App\Service\Content\Comments\Model\CommentAuthorModel;
use App\Service\Content\Comments\Model\EntryCommentModel;
use App\Service\Social\Social\Model\SocialCountsModel;
use App\Service\Social\Social\Pass\ReplyNode;
use App\Service\Social\Social\ReplyThreadOrder;
use PHPUnit\Framework\TestCase;

final class ReplyThreadOrderTest extends TestCase
{
    /** @var list<string> */
    private array $built = [];

    public function testTopLevelRepliesAreNewestFirst(): void
    {
        $page = (new ReplyThreadOrder(3, 5))->pageOf([
            $this->node('old', '2026-01-01'),
            $this->node('new', '2026-03-01'),
            $this->node('mid', '2026-02-01'),
        ]);

        self::assertSame(['new', 'mid', 'old'], $this->urls($page->comments));
    }

    public function testTopLevelRepliesAreMostInterestingFirst(): void
    {
        $page = (new ReplyThreadOrder(3, 5))->pageOf([
            $this->engaged('quiet', '2026-03-01', new SocialCountsModel(0, 0, 0, null)),
            $this->engaged('loud', '2026-01-01', new SocialCountsModel(9, 0, 0, null)),
            $this->engaged('mid', '2026-02-01', new SocialCountsModel(4, 0, 0, null)),
        ]);

        self::assertSame(['loud', 'mid', 'quiet'], $this->urls($page->comments));
    }

    public function testAReplyWeighsAsMuchAsThreeLikes(): void
    {
        $page = (new ReplyThreadOrder(3, 5))->pageOf([
            $this->engaged('replied', '2026-01-01', new SocialCountsModel(0, 0, 1, null)),
            $this->engaged('three likes', '2026-03-01', new SocialCountsModel(3, 0, 0, null)),
            $this->engaged('two likes', '2026-02-01', new SocialCountsModel(2, 0, 0, null)),
        ]);

        self::assertSame(['three likes', 'replied', 'two likes'], $this->urls($page->comments));
    }

    public function testARepostWeighsAsMuchAsTwoLikes(): void
    {
        $page = (new ReplyThreadOrder(3, 5))->pageOf([
            $this->engaged('reposted', '2026-01-01', new SocialCountsModel(0, 1, 0, null)),
            $this->engaged('two likes', '2026-03-01', new SocialCountsModel(2, 0, 0, 0)),
            $this->engaged('one like', '2026-02-01', new SocialCountsModel(1, 0, 0, 0)),
        ]);

        self::assertSame(['two likes', 'reposted', 'one like'], $this->urls($page->comments));
    }

    public function testAQuoteWeighsAsMuchAsTwoLikes(): void
    {
        $page = (new ReplyThreadOrder(3, 5))->pageOf([
            $this->engaged('quoted', '2026-01-01', new SocialCountsModel(0, 0, 0, 1)),
            $this->engaged('two likes', '2026-03-01', new SocialCountsModel(2, 0, 0, 0)),
            $this->engaged('one like', '2026-02-01', new SocialCountsModel(1, 0, 0, 0)),
        ]);

        self::assertSame(['two likes', 'quoted', 'one like'], $this->urls($page->comments));
    }

    public function testAReplyWithoutCountsRanksAsOneNobodyEngagedWith(): void
    {
        $page = (new ReplyThreadOrder(3, 5))->pageOf([
            $this->node('uncounted', '2026-03-01'),
            $this->engaged('unengaged', '2026-02-01', new SocialCountsModel(0, 0, 0, null)),
            $this->engaged('liked', '2026-01-01', new SocialCountsModel(1, 0, 0, null)),
        ]);

        self::assertSame(['liked', 'uncounted', 'unengaged'], $this->urls($page->comments));
    }

    public function testEquallyInterestingTopLevelRepliesAreNewestFirst(): void
    {
        $page = (new ReplyThreadOrder(3, 5))->pageOf([
            $this->engaged('old', '2026-01-01', new SocialCountsModel(3, 0, 0, null)),
            $this->engaged('new', '2026-03-01', new SocialCountsModel(3, 0, 0, null)),
        ]);

        self::assertSame(['new', 'old'], $this->urls($page->comments));
    }

    public function testRepliesInsideAThreadStayOldestFirstWhateverTheirInterest(): void
    {
        $page = (new ReplyThreadOrder(3, 5))->pageOf([
            $this->node(
                'root',
                '2026-01-01',
                $this->engaged('early', '2026-01-02', new SocialCountsModel(0, 0, 0, null)),
                $this->engaged('late', '2026-01-03', new SocialCountsModel(9, 0, 0, null)),
            ),
        ]);

        self::assertSame(['root', 'early', 'late'], $this->urls($page->comments));
    }

    public function testTheCountCapKeepsTheMostInterestingReplies(): void
    {
        $page = (new ReplyThreadOrder(3, 2))->pageOf([
            $this->engaged('newest', '2026-03-01', new SocialCountsModel(0, 0, 0, null)),
            $this->engaged('loud', '2026-01-01', new SocialCountsModel(7, 0, 0, null)),
            $this->engaged('mid', '2026-02-01', new SocialCountsModel(2, 0, 0, null)),
        ]);

        self::assertSame(['loud', 'mid'], $this->urls($page->comments));
        self::assertSame(1, $page->omitted);
    }

    public function testRepliesInsideAThreadAreOldestFirst(): void
    {
        $page = (new ReplyThreadOrder(3, 5))->pageOf([
            $this->node(
                'root',
                '2026-01-01',
                $this->node('late', '2026-01-03'),
                $this->node('early', '2026-01-02'),
            ),
        ]);

        self::assertSame(['root', 'early', 'late'], $this->urls($page->comments));
    }

    public function testEachCommentCarriesItsDepthInThreadOrder(): void
    {
        $page = (new ReplyThreadOrder(3, 5))->pageOf([
            $this->node('a', '2026-01-01', $this->node('a1', '2026-01-02', $this->node('a1x', '2026-01-03'))),
            $this->node('b', '2026-02-01', $this->node('b1', '2026-02-02')),
        ]);

        self::assertSame(
            [['b', 0], ['b1', 1], ['a', 0], ['a1', 1], ['a1x', 2]],
            array_map(static fn (EntryCommentModel $each): array => [$each->url, $each->depth], $page->comments),
        );
        self::assertSame(0, $page->omitted);
    }

    public function testRepliesBelowTheDepthCapAreCountedAsOmitted(): void
    {
        $chain = $this->node('d0', '2026-01-01', $this->node('d1', '2026-01-02', $this->node(
            'd2',
            '2026-01-03',
            $this->node('d3', '2026-01-04', $this->node('d4', '2026-01-05')),
        )));

        $page = (new ReplyThreadOrder(3, 5))->pageOf([$chain]);

        self::assertSame(['d0', 'd1', 'd2'], $this->urls($page->comments));
        self::assertSame(2, $page->omitted);
    }

    public function testTheCountCapKeepsWholeEarlierThreadsAndOmitsTheRest(): void
    {
        $page = (new ReplyThreadOrder(3, 5))->pageOf([
            $this->node('a', '2026-03-01', $this->node('a1', '2026-03-02'), $this->node('a2', '2026-03-03')),
            $this->node('b', '2026-02-01', $this->node('b1', '2026-02-02'), $this->node('b2', '2026-02-03')),
            $this->node('c', '2026-01-01'),
        ]);

        self::assertSame(['a', 'a1', 'a2', 'b', 'b1'], $this->urls($page->comments));
        self::assertSame(2, $page->omitted);
    }

    public function testBothCapsTogetherKeepTheShallowRepliesAndCountEveryOtherOne(): void
    {
        $page = (new ReplyThreadOrder(2, 3))->pageOf([
            $this->node('a', '2026-03-01', $this->node('a1', '2026-03-02', $this->node('a1x', '2026-03-03'))),
            $this->node('b', '2026-02-01', $this->node('b1', '2026-02-02'), $this->node('b2', '2026-02-03')),
        ]);

        self::assertSame(['a', 'a1', 'b'], $this->urls($page->comments));
        self::assertSame(3, $page->omitted);
    }

    public function testOnlyTheKeptRepliesAreBuilt(): void
    {
        (new ReplyThreadOrder(2, 3))->pageOf([
            $this->node('a', '2026-03-01', $this->node('a1', '2026-03-02', $this->node('a1x', '2026-03-03'))),
            $this->node('b', '2026-02-01', $this->node('b1', '2026-02-02')),
        ]);

        self::assertSame(['a', 'a1', 'b'], $this->built);
    }

    public function testAnUndatedReplySortsAsTheOldest(): void
    {
        $page = (new ReplyThreadOrder(3, 5))->pageOf([
            $this->node(
                'root',
                '2026-01-01',
                $this->node('dated', '2026-01-02'),
                $this->node('undated', null),
            ),
        ]);

        self::assertSame(['root', 'undated', 'dated'], $this->urls($page->comments));
    }

    public function testNoRepliesIsAnEmptyPage(): void
    {
        $page = (new ReplyThreadOrder(3, 5))->pageOf([]);

        self::assertSame([], $page->comments);
        self::assertSame(0, $page->omitted);
    }

    private function node(string $url, ?string $at, ReplyNode ...$replies): ReplyNode
    {
        return $this->engaged($url, $at, null, ...$replies);
    }

    private function engaged(string $url, ?string $at, ?SocialCountsModel $counts, ReplyNode ...$replies): ReplyNode
    {
        $publishedAt = $at === null ? null : new \DateTimeImmutable($at);
        $comment = function (?\DateTimeImmutable $publishedAt) use ($url): EntryCommentModel {
            $this->built[] = $url;

            return new EntryCommentModel(new CommentAuthorModel('Ann'), $url, $publishedAt, '<p>Hi</p>', false);
        };

        return new ReplyNode($publishedAt, $comment, array_values($replies), $url, false, $counts);
    }

    /**
     * @param list<EntryCommentModel> $comments
     * @return list<string|null>
     */
    private function urls(array $comments): array
    {
        return array_map(static fn (EntryCommentModel $comment): ?string => $comment->url, $comments);
    }
}
