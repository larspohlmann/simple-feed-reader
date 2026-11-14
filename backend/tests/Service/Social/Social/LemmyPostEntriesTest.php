<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social;

use App\Entity\DiscussionCounts;
use App\Enum\CommentsLoad;
use App\Service\Foundation\Url\Support\LemmyPostUrl;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Model\TimelinePageModel;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class LemmyPostEntriesTest extends TestCase
{
    private const string POST_LIST = __DIR__ . '/../../../Fixtures/lemmy/post-list.json';
    private const string LINK_POST = 'https://feddit.example/post/7';
    private const string TEXT_POST = 'https://lemmy.example/post/102';
    private const string MBIN_POST = 'https://mbin.example/m/rss/t/9';

    public function testEveryPostIsALemmyDiscussionThread(): void
    {
        $entries = self::entries();

        self::assertSame([self::LINK_POST, self::TEXT_POST, self::MBIN_POST], array_keys($entries));
        foreach ($entries as $entry) {
            self::assertNull($entry->url);
            self::assertSame(CommentsLoad::Auto, $entry->discussion->commentsLoad);
            self::assertNotNull(LemmyPostUrl::parse($entry->discussion->url));
        }
    }

    public function testALinkPostLeadsWithItsLink(): void
    {
        $linkPost = self::entries()[self::LINK_POST];

        self::assertStringStartsWith(
            '<p><a href="https://news.example/story">https://news.example/story</a></p>',
            (string) $linkPost->contentHtml,
        );
        self::assertSame('https://feddit.example/pictrs/image/thumb.jpeg', $linkPost->media->image?->url);
        self::assertSame('A link post', $linkPost->headline->text);
    }

    public function testTheBodyIsRenderedMarkdown(): void
    {
        $textPost = self::entries()[self::TEXT_POST];

        self::assertStringContainsString('<strong>bold</strong>', (string) $textPost->contentHtml);
    }

    public function testTheAuthorAndCountsAreThePosts(): void
    {
        $entries = self::entries();
        $linkPost = $entries[self::LINK_POST];

        self::assertSame('Ada', $linkPost->author);
        self::assertSame('https://feddit.example/u/ada', $linkPost->authorUrl);
        self::assertSame('https://feddit.example/pictrs/image/ada.png', $linkPost->authorAvatarUrl);
        self::assertEquals(DiscussionCounts::of(12, 3), $linkPost->discussionCounts);
        self::assertSame('lars', $entries[self::TEXT_POST]->author);
    }

    public function testPublishedTimesAreUtc(): void
    {
        $entries = self::entries();

        $expected = [self::LINK_POST => '2026-11-14 05:05:09', self::TEXT_POST => '2026-11-14 06:00:00'];
        foreach ($expected as $guid => $utc) {
            $published = $entries[$guid]->publishedAt;
            self::assertSame($utc, $published?->format('Y-m-d H:i:s'));
            self::assertSame('UTC', $published->getTimezone()->getName());
        }
    }

    public function testDeletedAndRemovedPostsAreSkipped(): void
    {
        $guids = array_keys(self::entries());

        self::assertCount(3, $guids);
        self::assertNotContains('https://lemmy.example/post/103', $guids);
        self::assertNotContains('https://lemmy.example/post/105', $guids);
    }

    public function testAnOriginThatIsNoLemmyPostPageUsesTheHomeInstancePage(): void
    {
        $mbinPost = self::entries()[self::MBIN_POST];

        self::assertSame('https://lemmy.example/post/104', $mbinPost->discussion->url);
        self::assertSame(self::MBIN_POST, $mbinPost->guid);
    }

    public function testTheNewestIdIsTheHighestPostId(): void
    {
        self::assertSame('105', self::page((string) file_get_contents(self::POST_LIST))->newestId);
        self::assertNull(self::page('{"posts":[]}')->newestId);
    }

    public function testAnAnswerWithoutPostsIsUnreachable(): void
    {
        $this->expectException(SocialNetworkUnreachableException::class);
        $this->expectExceptionMessage('The Lemmy instance answered without posts.');

        self::page('{"error":"odd"}');
    }

    /** @return array<string, ParsedEntryModel> keyed by guid */
    private static function entries(): array
    {
        $entries = [];
        foreach (self::page((string) file_get_contents(self::POST_LIST))->parsed->entries as $entry) {
            $entries[$entry->guid] = $entry;
        }

        return $entries;
    }

    private static function page(string $body): TimelinePageModel
    {
        return SocialApiWiring::lemmyPostEntries()->page(
            SocialAccounts::lemmyOf(SocialAccounts::owner()),
            new ApiResponseModel(Response::HTTP_OK, $body, []),
        );
    }
}
