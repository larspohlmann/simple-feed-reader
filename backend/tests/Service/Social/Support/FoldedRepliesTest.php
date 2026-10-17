<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Comments\Model\EntryCommentModel;
use App\Service\Social\Pass\ReplyNode;
use App\Service\Social\Support\FoldedReplies;
use PHPUnit\Framework\TestCase;

final class FoldedRepliesTest extends TestCase
{
    private const string FIRST_URL = 'https://mastodon.social/@Gargron/111';
    private const string SECOND_URL = 'https://mastodon.social/@Gargron/112';
    private const string OTHER_URL = 'https://mastodon.social/@Gargron/200';

    public function testAFoldedReplyIsReplacedByItsOwnRepliesInPlace(): void
    {
        $child = self::node('https://mastodon.social/@Gargron/113');
        $sibling = self::node('https://mastodon.social/@Gargron/114');
        $tree = [self::node(self::FIRST_URL, [self::node(self::SECOND_URL, [$child]), $sibling])];

        self::assertSame(
            [$child, $sibling],
            FoldedReplies::without($tree, [self::FIRST_URL, self::SECOND_URL]),
        );
    }

    public function testTheRepliesOfAFoldedReplyTakeItsPlaceAmongItsSiblings(): void
    {
        $unfolded = self::node(self::OTHER_URL);
        $child = self::node('https://mastodon.social/@Gargron/113');
        $sibling = self::node('https://mastodon.social/@Gargron/114');
        $tree = [$unfolded, self::node(self::FIRST_URL, [self::node(self::SECOND_URL, [$child]), $sibling])];

        self::assertSame(
            [$unfolded, $child, $sibling],
            FoldedReplies::without($tree, [self::FIRST_URL, self::SECOND_URL]),
        );
    }

    public function testATreeWithNoFoldedReplyComesBackUnchanged(): void
    {
        $tree = [self::node(self::FIRST_URL, [self::node(self::SECOND_URL)]), self::node(null)];

        self::assertSame($tree, FoldedReplies::without($tree, []));
        self::assertSame($tree, FoldedReplies::without($tree, [self::OTHER_URL]));
    }

    public function testAFoldedReplyDeeperInTheTreeLiftsItsRepliesIntoItsParent(): void
    {
        $child = self::node('https://mastodon.social/@Gargron/113');
        $sibling = self::node('https://mastodon.social/@Gargron/114');
        $publishedAt = new \DateTimeImmutable('2026-10-16 08:00:00');
        $parent = self::node(self::OTHER_URL, [self::node(self::FIRST_URL, [$child]), $sibling], $publishedAt);

        $result = FoldedReplies::without([$parent], [self::FIRST_URL]);

        self::assertCount(1, $result);
        self::assertNotSame($parent, $result[0]);
        self::assertSame([$child, $sibling], $result[0]->replies);
        self::assertSame(self::OTHER_URL, $result[0]->url);
        self::assertSame($publishedAt, $result[0]->publishedAt);
    }

    /** @param list<ReplyNode> $replies */
    private static function node(?string $url, array $replies = [], ?\DateTimeImmutable $publishedAt = null): ReplyNode
    {
        return new ReplyNode(
            $publishedAt,
            static fn (): EntryCommentModel => throw new \LogicException('Not rendered in this test.'),
            $replies,
            $url,
            false,
        );
    }
}
