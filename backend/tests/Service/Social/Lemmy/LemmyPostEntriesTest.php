<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Lemmy;

use App\Entity\DiscussionCounts;
use App\Entity\PostCommunity;
use App\Enum\CommentsLoad;
use App\Service\Foundation\Url\Support\LemmyPostUrl;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Model\TimelinePageModel;
use App\Tests\Support\ReadsFixtures;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class LemmyPostEntriesTest extends TestCase
{
    use ReadsFixtures;

    private const string POST_LIST = 'lemmy/post-list.json';
    private const string LINK_POST = 'https://feddit.example/post/7';
    private const string TEXT_POST = 'https://lemmy.example/post/102';
    private const string MBIN_POST = 'https://mbin.example/m/rss/t/9';

    public function testEveryPostIsALemmyDiscussionThread(): void
    {
        $entries = $this->entries();

        self::assertSame([self::LINK_POST, self::TEXT_POST, self::MBIN_POST], array_keys($entries));
        foreach ($entries as $entry) {
            self::assertNull($entry->url);
            self::assertSame(CommentsLoad::Auto, $entry->discussion->commentsLoad);
            self::assertNotNull(LemmyPostUrl::parse($entry->discussion->url));
        }
    }

    public function testALinkPostLeadsWithItsLink(): void
    {
        $linkPost = $this->entries()[self::LINK_POST];

        self::assertStringStartsWith(
            '<p><a href="https://news.example/story">https://news.example/story</a></p>',
            (string) $linkPost->contentHtml,
        );
        self::assertSame('https://feddit.example/pictrs/image/thumb.jpeg', $linkPost->media->image?->url);
        self::assertSame('A link post', $linkPost->headline->text);
    }

    public function testALinkPostCarriesItsLinkedUrl(): void
    {
        self::assertSame('https://news.example/story', $this->entries()[self::LINK_POST]->linkedUrl);
        self::assertNull($this->entries()[self::TEXT_POST]->linkedUrl);
    }

    public function testTheBodyIsRenderedMarkdown(): void
    {
        $textPost = $this->entries()[self::TEXT_POST];

        self::assertStringContainsString('<strong>bold</strong>', (string) $textPost->contentHtml);
    }

    public function testTheAuthorAndCountsAreThePosts(): void
    {
        $entries = $this->entries();
        $linkPost = $entries[self::LINK_POST];

        self::assertSame('Ada', $linkPost->author);
        self::assertSame('https://feddit.example/u/ada', $linkPost->authorUrl);
        self::assertSame('https://feddit.example/pictrs/image/ada.png', $linkPost->authorAvatarUrl);
        self::assertEquals(DiscussionCounts::fromScoreAndComments(12, 3), $linkPost->discussionCounts);
        self::assertSame('lars', $entries[self::TEXT_POST]->author);
    }

    public function testAPostNamesItsCommunity(): void
    {
        self::assertEquals(
            PostCommunity::fromIdentityAndUrl('rss@feddit.example', 'https://feddit.example/c/rss'),
            $this->entries()[self::LINK_POST]->community,
        );
    }

    public function testACommunityOffAnHttpsInstanceIsLeftOutButTheScoreStays(): void
    {
        $body = str_replace(
            'https://feddit.example/c/rss',
            'http://old.example/c/rss',
            $this->fixture(self::POST_LIST),
        );
        $entry = self::page($body)->parsed->entries[0];

        self::assertNull($entry->community);
        self::assertSame(12, $entry->discussionCounts->score);
    }

    public function testPublishedTimesAreUtc(): void
    {
        $entries = $this->entries();

        $expected = [self::LINK_POST => '2026-11-14 05:05:09', self::TEXT_POST => '2026-11-14 06:00:00'];
        foreach ($expected as $guid => $utc) {
            $published = $entries[$guid]->publishedAt;
            self::assertSame($utc, $published?->format('Y-m-d H:i:s'));
            self::assertSame('UTC', $published->getTimezone()->getName());
        }
    }

    public function testDeletedAndRemovedPostsAreSkipped(): void
    {
        $guids = array_keys($this->entries());

        self::assertCount(3, $guids);
        self::assertNotContains('https://lemmy.example/post/103', $guids);
        self::assertNotContains('https://lemmy.example/post/105', $guids);
    }

    public function testAnOriginThatIsNoLemmyPostPageUsesTheHomeInstancePage(): void
    {
        $mbinPost = $this->entries()[self::MBIN_POST];

        self::assertSame('https://lemmy.example/post/104', $mbinPost->discussion->url);
        self::assertSame(self::MBIN_POST, $mbinPost->guid);
    }

    public function testAccountPostCarriesItsHomeAddress(): void
    {
        $linkPost = $this->entries()[self::LINK_POST];

        self::assertSame('https://lemmy.example/post/101', $linkPost->discussion->homeUrl);
        self::assertSame(self::LINK_POST, $linkPost->discussion->url);
    }

    public function testAPostStatesItsLanguageByTheInstancesLanguageId(): void
    {
        $entries = $this->entriesWithLanguageIds([32, 130, 37, 0, 47]);

        self::assertSame('de', $entries[self::LINK_POST]->statedLanguage);
        self::assertSame('x-lemmy-130', $entries[self::TEXT_POST]->statedLanguage);
        self::assertNull($entries[self::MBIN_POST]->statedLanguage);
    }

    public function testAPostWithoutALanguageIdStatesNone(): void
    {
        self::assertNull($this->entries()[self::LINK_POST]->statedLanguage);
    }

    public function testTheNewestIdIsTheHighestPostId(): void
    {
        self::assertSame('105', self::page($this->fixture(self::POST_LIST))->newestId);
        self::assertNull(self::page('{"posts":[]}')->newestId);
    }

    public function testAnAnswerWithoutPostsIsUnreachable(): void
    {
        $this->expectException(SocialNetworkUnreachableException::class);
        $this->expectExceptionMessage('The Lemmy instance answered without posts.');

        self::page('{"error":"odd"}');
    }

    public function testOnlyPostsTheQueryMatchesBecomeEntries(): void
    {
        $page = self::searchPage(
            'rust compiler',
            [[1 => 'Rust compiler news', 2 => 'Trusted compilers', 3 => 'Compilers written in Rust']],
        );

        self::assertSame(['https://lemmy.example/post/3', 'https://lemmy.example/post/1'], self::guidsOf($page));
    }

    public function testAPhraseIsCheckedByTheReader(): void
    {
        $page = self::searchPage('"rss reader"', [[1 => 'A reader for RSS', 2 => 'My RSS reader']]);

        self::assertSame(['https://lemmy.example/post/2'], self::guidsOf($page));
    }

    public function testAPhraseNeverSpansTitleAndBody(): void
    {
        $posts = [[1 => 'My RSS']];

        self::assertSame([], self::guidsOf(self::searchPage('"rss reader"', $posts, 'reader notes')));
        self::assertSame(
            ['https://lemmy.example/post/1'],
            self::guidsOf(self::searchPage('rss reader', $posts, 'reader notes')),
        );
    }

    public function testAnExcludedWordDropsThePost(): void
    {
        $page = self::searchPage('rss -gazette', [[1 => 'RSS gazette', 2 => 'RSS news']]);

        self::assertSame(['https://lemmy.example/post/2'], self::guidsOf($page));
    }

    public function testAPostFoundByTwoAlternativesAppearsOnce(): void
    {
        $page = self::searchPage('rss || atom', [[1 => 'rss', 2 => 'rss atom'], [2 => 'rss atom']]);

        self::assertSame(['https://lemmy.example/post/2', 'https://lemmy.example/post/1'], self::guidsOf($page));
    }

    public function testThePageIsNewestFirstAcrossAnswers(): void
    {
        $page = self::searchPage('news || story', [[1 => 'news', 3 => 'news'], [2 => 'story', 4 => 'story']]);

        self::assertSame(
            [
                'https://lemmy.example/post/4',
                'https://lemmy.example/post/3',
                'https://lemmy.example/post/2',
                'https://lemmy.example/post/1',
            ],
            self::guidsOf($page),
        );
    }

    public function testTheNewestSeenIdCoversEveryAnswer(): void
    {
        $page = self::searchPage('news || story', [[1 => 'news'], [4 => 'unrelated']]);

        self::assertSame('4', $page->newestId);
    }

    public function testAnAnswerWithoutPostsIsUnreachableInASearch(): void
    {
        $this->expectException(SocialNetworkUnreachableException::class);
        $this->expectExceptionMessage('The Lemmy instance answered without posts.');

        SocialApiWiring::lemmyPostEntries()->searchPage(
            SocialAccounts::lemmyOf(SocialAccounts::owner()),
            [new ApiResponseModel(Response::HTTP_OK, '{"error":"odd"}', [])],
            'news',
        );
    }

    /** @param list<array<int, string>> $titlesByPostId one answer per element, each mapping post id to title */
    private static function searchPage(string $query, array $titlesByPostId, ?string $body = null): TimelinePageModel
    {
        return SocialApiWiring::lemmyPostEntries()->searchPage(
            SocialAccounts::lemmyOf(SocialAccounts::owner()),
            array_map(
                static fn (array $titles): ApiResponseModel => new ApiResponseModel(
                    Response::HTTP_OK,
                    json_encode(['posts' => array_map(
                        static fn (int $postId, string $title): array => [
                            'post' => [
                                'id' => $postId,
                                'name' => $title,
                                'ap_id' => 'https://lemmy.example/post/' . $postId,
                                'published' => '2026-11-14T05:05:09Z',
                                ...(null === $body ? [] : ['body' => $body]),
                            ],
                            'creator' => ['name' => 'ada'],
                            'counts' => ['score' => 1, 'comments' => 0],
                        ],
                        array_keys($titles),
                        $titles,
                    )], JSON_THROW_ON_ERROR),
                    [],
                ),
                $titlesByPostId,
            ),
            $query,
        );
    }

    /** @return list<string> */
    private static function guidsOf(TimelinePageModel $page): array
    {
        return array_map(static fn (ParsedEntryModel $entry): string => $entry->guid, $page->parsed->entries);
    }

    /** @return array<string, ParsedEntryModel> keyed by guid */
    private function entries(): array
    {
        $entries = [];
        foreach (self::page($this->fixture(self::POST_LIST))->parsed->entries as $entry) {
            $entries[$entry->guid] = $entry;
        }

        return $entries;
    }

    /**
     * @param list<int> $languageIds the language id of each post of the fixture, in its order
     *
     * @return array<string, ParsedEntryModel> keyed by guid
     */
    private function entriesWithLanguageIds(array $languageIds): array
    {
        $body = preg_replace_callback(
            '/"language_id": 0/',
            static function () use (&$languageIds): string {
                return '"language_id": ' . (array_shift($languageIds) ?? 0);
            },
            $this->fixture(self::POST_LIST),
        );

        $entries = [];
        foreach (self::page((string) $body)->parsed->entries as $entry) {
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
