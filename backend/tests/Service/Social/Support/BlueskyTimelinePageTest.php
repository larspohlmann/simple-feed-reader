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

    /** @param array<string, mixed> $overrides */
    private static function item(array $overrides = []): JsonNodeModel
    {
        return JsonNodeModel::of($overrides + [
            'post' => [
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
