<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Parser\Support\EntryTitle;
use App\Service\Social\Support\BlueskyTimelinePage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlueskyTimelinePageTest extends TestCase
{
    private const string ALICE = 'did:plc:alice';
    private const string BOB = 'did:plc:bob';
    private const string POST_URI = 'at://did:plc:bob/app.bsky.feed.post/3abc';
    private const string ROOT_URI = 'at://did:plc:bob/app.bsky.feed.post/3root';
    private const string PARENT_URI = 'at://did:plc:bob/app.bsky.feed.post/3parent';

    public function testAPostBecomesAnEntryWithItsWebUrlAuthorAndUtcDate(): void
    {
        $entry = BlueskyTimelinePage::of([self::item()], self::ALICE)->parsed->entries[0];

        self::assertSame(self::POST_URI, $entry->guid);
        self::assertSame('https://bsky.app/profile/did:plc:bob/post/3abc', $entry->url);
        self::assertSame('Bob', $entry->author);
        self::assertSame('https://bsky.app/profile/bob.bsky.social', $entry->authorUrl);
        self::assertSame('https://cdn.bsky.app/img/avatar/plain/did:plc:bob/abc@jpeg', $entry->authorAvatarUrl);
        self::assertSame('<p>Hello world. More text</p>', $entry->contentHtml);
        self::assertEquals(EntryTitle::of(null, '<p>Hello world. More text</p>'), $entry->headline);
        self::assertTrue($entry->headline->derived);
        self::assertSame('2026-10-12 08:00:00', $entry->publishedAt?->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $entry->publishedAt->getTimezone()->getName());
    }

    public function testThePostViewIsKeptForItsEmbedAndThePageNamesNoTitle(): void
    {
        $page = BlueskyTimelinePage::of([self::item()], self::ALICE);

        self::assertSame(self::POST_URI, $page->knownShares->blueskyPostFor(self::POST_URI)?->string('uri'));
        self::assertNull($page->parsed->title);
        self::assertNull($page->newestId);
    }

    public function testAPostTheNetworkCountsRepliesForIsKnownAsReplied(): void
    {
        $page = BlueskyTimelinePage::of([self::item(post: ['replyCount' => 1])], self::ALICE);

        self::assertTrue($page->knownShares->hasRepliesFor(self::POST_URI));
    }

    public function testAPostWithoutRepliesIsNotKnownAsReplied(): void
    {
        $unreplied = BlueskyTimelinePage::of([self::item(post: ['replyCount' => 0])], self::ALICE);
        $uncounted = BlueskyTimelinePage::of([self::item()], self::ALICE);

        self::assertFalse($unreplied->knownShares->hasRepliesFor(self::POST_URI));
        self::assertFalse($uncounted->knownShares->hasRepliesFor(self::POST_URI));
    }

    public function testARepostShowsTheOriginalWithATrailingAttributionAtTheRepostTime(): void
    {
        $item = self::item(['reason' => [
            '$type' => 'app.bsky.feed.defs#reasonRepost',
            'by' => ['did' => 'did:plc:carol', 'handle' => 'carol.bsky.social', 'displayName' => 'Carol <3'],
            'indexedAt' => '2026-10-12T09:30:00Z',
        ]]);

        $entry = BlueskyTimelinePage::of([$item], self::ALICE)->parsed->entries[0];

        self::assertSame(
            '<p>Hello world. More text</p>'
            . '<p>Reposted by <a href="https://bsky.app/profile/carol.bsky.social">Carol &lt;3</a></p>',
            $entry->contentHtml,
        );
        self::assertEquals(EntryTitle::of(null, '<p>Hello world. More text</p>'), $entry->headline);
        self::assertSame('2026-10-12 09:30:00', $entry->publishedAt?->format('Y-m-d H:i:s'));
    }

    /** @param array<string, string> $reposter */
    #[DataProvider('malformedReposters')]
    public function testAReposterWithoutANameOrAProfileIsAttributedAsFarAsItCanBe(
        array $reposter,
        string $expected,
    ): void {
        $item = self::item(['reason' => ['$type' => 'app.bsky.feed.defs#reasonRepost', 'by' => $reposter]]);

        self::assertSame($expected, BlueskyTimelinePage::of([$item], self::ALICE)->parsed->entries[0]->contentHtml);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function malformedReposters(): iterable
    {
        yield 'no name' => [['did' => 'did:plc:carol'], '<p>Hello world. More text</p>'];
        yield 'a name but no profile' => [
            ['displayName' => 'Carol <3'],
            '<p>Hello world. More text</p><p>Reposted by Carol &lt;3</p>',
        ];
    }

    /** @param array<string, mixed> $parent */
    #[DataProvider('replies')]
    public function testAReplyShowsOnlyWhenItAnswersTheAccountOrSomeoneItFollows(array $parent, int $shown): void
    {
        $item = self::item(['reply' => ['root' => $parent, 'parent' => $parent]]);

        self::assertCount($shown, BlueskyTimelinePage::of([$item], self::ALICE)->parsed->entries);
    }

    /** @return iterable<string, array{array<string, mixed>, int}> */
    public static function replies(): iterable
    {
        yield 'to the account' => [['author' => ['did' => self::ALICE, 'viewer' => []]], 1];
        yield 'to a followed account' => [
            [
                'author' => [
                    'did' => 'did:plc:dan',
                    'viewer' => ['following' => 'at://did:plc:alice/app.bsky.graph.follow/1'],
                ],
            ],
            1,
        ];
        yield 'to a stranger' => [['author' => ['did' => 'did:plc:eve', 'viewer' => []]], 0];
        yield 'to a deleted post' => [['$type' => 'app.bsky.feed.defs#notFoundPost', 'notFound' => true], 0];
    }

    public function testAnItemWithoutAPostUriIsSkipped(): void
    {
        $item = JsonNodeModel::of(['post' => ['record' => ['text' => 'orphan']]]);

        self::assertSame([], BlueskyTimelinePage::of([$item], self::ALICE)->parsed->entries);
    }

    public function testAnItemWhoseUriIsNoPostIsSkipped(): void
    {
        $item = JsonNodeModel::of(['post' => ['uri' => 'at://did:plc:bob/app.bsky.feed.like/1']]);

        self::assertSame([], BlueskyTimelinePage::of([$item], self::ALICE)->parsed->entries);
    }

    public function testAHiddenReplyDoesNotEndThePage(): void
    {
        $hidden = self::item(['reply' => ['parent' => ['author' => ['did' => 'did:plc:eve', 'viewer' => []]]]]);

        self::assertCount(1, BlueskyTimelinePage::of([$hidden, self::item()], self::ALICE)->parsed->entries);
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('authorFeedItems')]
    public function testAnAuthorFeedListsOnlyTheProfilesOwnPostsAndSelfThreads(array $overrides, int $shown): void
    {
        self::assertCount($shown, BlueskyTimelinePage::ofAuthor([self::item($overrides)], self::BOB)->parsed->entries);
    }

    /** @return iterable<string, array{array<string, mixed>, int}> */
    public static function authorFeedItems(): iterable
    {
        yield 'an own post' => [[], 1];
        yield 'a repost' => [
            ['reason' => ['$type' => 'app.bsky.feed.defs#reasonRepost', 'by' => ['did' => self::BOB]]],
            0,
        ];
        yield 'a self-thread reply' => [
            ['reply' => ['parent' => ['author' => ['did' => self::BOB, 'viewer' => []]]]],
            1,
        ];
        yield 'a reply to someone else' => [
            ['reply' => ['parent' => ['author' => [
                'did' => 'did:plc:eve',
                'viewer' => ['following' => 'at://x/y/1'],
            ]]]],
            0,
        ];
        yield 'a reply to a deleted post' => [['reply' => ['parent' => ['notFound' => true]]], 0];
    }

    public function testAnAuthorsReplyToTheirOwnThreadIsHandedOnNotListed(): void
    {
        $item = self::item(['reply' => self::reply(self::BOB, self::BOB, self::PARENT_URI)]);

        $page = BlueskyTimelinePage::of([$item], self::ALICE);

        self::assertSame([], $page->parsed->entries);
        $selfReply = $page->selfReplies[0];
        self::assertSame(self::ROOT_URI, $selfReply->rootGuid);
        self::assertSame('https://bsky.app/profile/did:plc:bob/post/3parent', $selfReply->parentUrl);
        self::assertSame(self::POST_URI, $selfReply->entry->guid);
        self::assertSame(self::POST_URI, $selfReply->post->string('uri'));
        self::assertSame(self::POST_URI, $page->knownShares->blueskyPostFor(self::POST_URI)?->string('uri'));
    }

    public function testTheAuthorsOwnFeedHandsItsSelfRepliesOnToo(): void
    {
        $item = self::item(['reply' => self::reply(self::BOB, self::BOB, self::ROOT_URI)]);

        $page = BlueskyTimelinePage::ofAuthor([$item], self::BOB);

        self::assertSame([], $page->parsed->entries);
        self::assertSame('https://bsky.app/profile/did:plc:bob/post/3root', $page->selfReplies[0]->parentUrl);
    }

    /** @param array<string, mixed> $item */
    #[DataProvider('notSelfReplies')]
    public function testAnythingButASelfReplyStaysAnEntry(array $item): void
    {
        $page = BlueskyTimelinePage::of([self::item($item)], self::BOB);

        self::assertCount(1, $page->parsed->entries);
        self::assertSame([], $page->selfReplies);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function notSelfReplies(): iterable
    {
        yield 'a reply to someone else' => [['reply' => self::reply(self::ALICE, self::ALICE, self::PARENT_URI)]];
        yield 'a self-reply under someone else\'s root' => [
            ['reply' => self::reply(self::ALICE, self::BOB, self::PARENT_URI)],
        ];
        yield 'a repost of a self-reply' => [[
            'reply' => self::reply(self::BOB, self::BOB, self::PARENT_URI),
            'reason' => [
                '$type' => 'app.bsky.feed.defs#reasonRepost',
                'by' => ['did' => self::ALICE],
                'indexedAt' => '2026-10-12T09:30:00Z',
            ],
        ]];
        yield 'a reply without its root' => [[
            'reply' => ['parent' => ['uri' => self::PARENT_URI, 'author' => ['did' => self::BOB]]],
        ]];
    }

    /** @return array<string, mixed> */
    private static function reply(string $rootAuthor, string $parentAuthor, string $parentUri): array
    {
        $followed = ['following' => 'at://did:plc:bob/app.bsky.graph.follow/1'];

        return [
            'root' => ['uri' => self::ROOT_URI, 'author' => ['did' => $rootAuthor, 'viewer' => $followed]],
            'parent' => ['uri' => $parentUri, 'author' => ['did' => $parentAuthor, 'viewer' => $followed]],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @param array<string, mixed> $post
     */
    private static function item(array $overrides = [], array $post = []): JsonNodeModel
    {
        return JsonNodeModel::of($overrides + [
            'post' => $post + [
                'uri' => self::POST_URI,
                'author' => ['did' => 'did:plc:bob', 'handle' => 'bob.bsky.social', 'displayName' => 'Bob',
                    'avatar' => 'https://cdn.bsky.app/img/avatar/plain/did:plc:bob/abc@jpeg',
                ],
                'record' => [
                    '$type' => 'app.bsky.feed.post',
                    'text' => 'Hello world. More text',
                    'createdAt' => '2026-10-12T10:00:00+02:00',
                ],
                'indexedAt' => '2026-10-12T08:00:05Z',
            ],
        ]);
    }
}
