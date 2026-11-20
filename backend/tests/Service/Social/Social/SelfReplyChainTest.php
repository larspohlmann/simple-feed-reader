<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social;

use App\Service\Content\Comments\Model\CommentAuthorModel;
use App\Service\Content\Comments\Model\EntryCommentModel;
use App\Service\Social\Social\Pass\ReplyNode;
use App\Service\Social\Social\SelfReplyChain;
use PHPUnit\Framework\TestCase;

final class SelfReplyChainTest extends TestCase
{
    public function testFollowsTheAuthorsOldestReplyAtEachLevel(): void
    {
        $chain = (new SelfReplyChain())->buildChain([
            self::node('stranger', '08:15:40', false),
            self::node('second-by-author', '08:15:50', true),
            self::node('first-by-author', '08:15:32', true, [
                self::node('stranger-below', '08:15:33', false),
                self::node('third', '08:15:34', true, [
                    self::node('fourth', '08:15:35', true, [
                        self::node('fifth', '08:15:36', true, [self::node('sixth', '08:15:37', true)]),
                    ]),
                ]),
            ]),
        ]);

        self::assertSame(
            ['first-by-author', 'third', 'fourth', 'fifth'],
            array_map(static fn (EntryCommentModel $reply): string => $reply->html, $chain->replies),
        );
    }

    public function testCountsTheRepliesItDoesNotKeep(): void
    {
        $chain = (new SelfReplyChain())->buildChain([
            self::node('one', '08:00:00', true, [
                self::node('two', '08:01:00', true, [
                    self::node('three', '08:02:00', true, [
                        self::node('four', '08:03:00', true, [
                            self::node('five', '08:04:00', true, [self::node('stranger', '08:05:00', false)]),
                        ]),
                    ]),
                ]),
            ]),
        ]);

        self::assertCount(SelfReplyChain::MAX_REPLIES, $chain->replies);
        self::assertSame(5, $chain->replyCount);
    }

    public function testStopsWhereNoReplyIsTheAuthors(): void
    {
        $chain = (new SelfReplyChain())->buildChain([
            self::node('first', '08:00:00', true, [self::node('stranger', '08:01:00', false)]),
        ]);

        self::assertCount(1, $chain->replies);
        self::assertSame(1, $chain->replyCount);
    }

    public function testNoAuthorReplyMeansNoChain(): void
    {
        $chain = (new SelfReplyChain())->buildChain([self::node('stranger', '08:00:00', false)]);

        self::assertSame([], $chain->replies);
        self::assertSame(0, $chain->replyCount);
    }

    public function testAReplyWithoutADateComesAfterDatedOnes(): void
    {
        $chain = (new SelfReplyChain())->buildChain([
            self::node('undated', null, true),
            self::node('dated', '09:00:00', true),
        ]);

        self::assertSame('dated', $chain->replies[0]->html);
    }

    /** @param list<ReplyNode> $replies */
    private static function node(string $html, ?string $time, bool $byAuthor, array $replies = []): ReplyNode
    {
        $publishedAt = $time === null ? null : new \DateTimeImmutable('2026-10-17 ' . $time);

        return new ReplyNode(
            $publishedAt,
            static fn (?\DateTimeImmutable $at): EntryCommentModel => new EntryCommentModel(
                new CommentAuthorModel('A', null, null, null),
                'https://x/' . $html,
                $at,
                $html,
                $byAuthor,
            ),
            $replies,
            url: 'https://x/' . $html,
            byEntryAuthor: $byAuthor,
            counts: null,
        );
    }
}
