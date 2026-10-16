<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Comments\Model\CommentAuthorModel;
use App\Service\Comments\Model\EntryCommentModel;
use App\Service\Social\Pass\ReplyNode;
use App\Service\Social\ReplyThreadOrder;
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
        $publishedAt = $at === null ? null : new \DateTimeImmutable($at);
        $comment = function (?\DateTimeImmutable $publishedAt) use ($url): EntryCommentModel {
            $this->built[] = $url;

            return new EntryCommentModel(new CommentAuthorModel('Ann'), $url, $publishedAt, '<p>Hi</p>', false);
        };

        return new ReplyNode($publishedAt, $comment, array_values($replies));
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
