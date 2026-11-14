<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Support;

use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Support\BlueskySearchPage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class BlueskySearchPageTest extends TestCase
{
    public function testEveryPostButAReplyBecomesAnEntry(): void
    {
        $page = BlueskySearchPage::of(self::answer(['posts' => [
            self::post('at://did:plc:amy/app.bsky.feed.post/1', 'Which RSS reader do you use?'),
            self::post('at://did:plc:bob/app.bsky.feed.post/2', 'NetNewsWire is my RSS reader', reply: true),
        ]]), 'did:plc:me', 'rss reader');

        self::assertSame(
            ['at://did:plc:amy/app.bsky.feed.post/1'],
            array_map(static fn (ParsedEntryModel $entry): string => $entry->guid, $page->parsed->entries),
        );
        self::assertSame([], $page->selfReplies);
    }

    public function testAPostKeepsItsWebUrlAndItsPostViewForItsEmbed(): void
    {
        $uri = 'at://did:plc:amy/app.bsky.feed.post/1';

        $page = BlueskySearchPage::of(self::answer(['posts' => [self::post($uri, 'Hello')]]), 'did:plc:me', 'hello');

        self::assertSame('https://bsky.app/profile/did:plc:amy/post/1', $page->parsed->entries[0]->url);
        self::assertSame($uri, $page->knownShares->blueskyPostFor($uri)?->string('uri'));
    }

    public function testAnAnswerWithoutPostsIsAnUnreachableBluesky(): void
    {
        $this->expectException(SocialNetworkUnreachableException::class);

        BlueskySearchPage::of(self::answer(['cursor' => 'x']), 'did:plc:me', 'rss');
    }

    public function testAPostMatchingOnlyInItsLinkCardIsLeftOut(): void
    {
        $post = self::post('at://did:plc:amy/app.bsky.feed.post/1', 'Worth a read');
        $post['embed'] = ['$type' => 'app.bsky.embed.external#view', 'external' => [
            'uri' => 'https://example.com/rss',
            'title' => 'The best RSS reader',
            'description' => 'An RSS reader review',
        ]];

        $page = BlueskySearchPage::of(self::answer(['posts' => [$post]]), 'did:plc:me', '"rss reader"');

        self::assertSame([], $page->parsed->entries);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function quoteEmbeds(): iterable
    {
        $quoted = ['$type' => 'app.bsky.embed.record#viewRecord', 'value' => ['text' => 'My RSS reader of choice']];
        yield 'a quote' => [['$type' => 'app.bsky.embed.record#view', 'record' => $quoted]];
        yield 'a quote with media' => [[
            '$type' => 'app.bsky.embed.recordWithMedia#view',
            'record' => ['$type' => 'app.bsky.embed.record#view', 'record' => $quoted],
        ]];
    }

    /** @param array<string, mixed> $embed */
    #[DataProvider('quoteEmbeds')]
    public function testAPostMatchingInThePostItQuotesIsKept(array $embed): void
    {
        $post = self::post('at://did:plc:amy/app.bsky.feed.post/1', 'Same here');
        $post['embed'] = $embed;

        $page = BlueskySearchPage::of(self::answer(['posts' => [$post]]), 'did:plc:me', '"rss reader"');

        self::assertCount(1, $page->parsed->entries);
    }

    public function testAPhraseNeverSpansAPostAndTheOneItQuotes(): void
    {
        $post = self::post('at://did:plc:amy/app.bsky.feed.post/1', 'I want rss');
        $post['embed'] = ['$type' => 'app.bsky.embed.record#view', 'record' => ['value' => ['text' => 'reader apps']]];

        $page = BlueskySearchPage::of(self::answer(['posts' => [$post]]), 'did:plc:me', '"rss reader"');

        self::assertSame([], $page->parsed->entries);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function authorFilters(): iterable
    {
        yield 'an excluded author' => ['rss -from:hamburgnews.bsky.social', ['amy']];
        yield 'an author by handle' => ['rss from:@HamburgNews.bsky.social', ['hamburg']];
        yield 'an author by DID' => ['rss from:did:plc:hamburgnews', ['hamburg']];
    }

    /** @param list<string> $kept */
    #[DataProvider('authorFilters')]
    public function testAFromFilterIsCheckedAgainstTheAuthorsHandleAndDid(string $query, array $kept): void
    {
        $hamburg = self::post('at://did:plc:hamburgnews/app.bsky.feed.post/hamburg', 'RSS news');
        $hamburg['author'] = ['did' => 'did:plc:hamburgnews', 'handle' => 'hamburgnews.bsky.social'];

        $page = BlueskySearchPage::of(self::answer(['posts' => [
            $hamburg,
            self::post('at://did:plc:amy/app.bsky.feed.post/amy', 'RSS news'),
        ]]), 'did:plc:me', $query);

        self::assertSame($kept, array_map(
            static fn (ParsedEntryModel $entry): string => basename($entry->guid),
            $page->parsed->entries,
        ));
    }

    /** @param array<mixed> $body */
    private static function answer(array $body): ApiResponseModel
    {
        return new ApiResponseModel(Response::HTTP_OK, json_encode($body, \JSON_THROW_ON_ERROR), []);
    }

    /** @return array<string, mixed> */
    private static function post(string $uri, string $text, bool $reply = false): array
    {
        $record = ['$type' => 'app.bsky.feed.post', 'text' => $text, 'createdAt' => '2026-11-07T10:00:00.000Z'];
        if ($reply) {
            $parent = ['uri' => 'at://did:plc:amy/app.bsky.feed.post/1', 'cid' => 'c1'];
            $record['reply'] = ['parent' => $parent, 'root' => $parent];
        }

        return [
            'uri' => $uri,
            'cid' => 'c2',
            'author' => ['did' => 'did:plc:amy', 'handle' => 'amy.bsky.social', 'displayName' => 'Amy'],
            'record' => $record,
            'indexedAt' => '2026-11-07T10:00:01.000Z',
        ];
    }
}
