<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Lemmy;

use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Social\Lemmy\LemmySearchSource;
use App\Service\Social\Social\Exception\SearchQueryTooBroadException;
use App\Tests\Support\ReadsFixtures;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LemmySearchSourceTest extends TestCase
{
    use ReadsFixtures;

    private const string LEMMY_SEARCH = 'GET https://lemmy.example/api/v3/search'
        . '?q=rss%20reader&type_=Posts&sort=New&listing_type=All&limit=50';
    private const string LEMMY_WORD_SEARCH = 'GET https://lemmy.example/api/v3/search'
        . '?q=post&type_=Posts&sort=New&listing_type=All&limit=50';
    private const string LEMMY_FOUND = 'lemmy/search.json';

    public function testASearchPreviewsThePostsFound(): void
    {
        $http = new RoutedHttpClient([
            self::LEMMY_WORD_SEARCH => new MockResponse($this->fixture(self::LEMMY_FOUND)),
        ]);

        $page = $this->source($http->client())->fetchNewest(SocialApiWiring::lemmyAccount(), 'post');

        self::assertSame(
            ['https://lemmy.example/post/102', 'https://feddit.example/post/7'],
            array_map(static fn (ParsedEntryModel $entry): string => $entry->guid, $page->parsed->entries),
        );
        self::assertSame([self::LEMMY_WORD_SEARCH], $http->requestLines());
        self::assertContains('Authorization: Bearer ' . SocialAccounts::LEMMY_JWT, $http->requests[0]['headers']);
    }

    public function testASearchPreviewWaitsLongerForItsAnswer(): void
    {
        $seenOptions = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seenOptions) {
            $seenOptions = $options;

            return self::lemmyAnswer([]);
        });

        $this->source($http)->fetchNewest(SocialApiWiring::lemmyAccount(), 'post');

        self::assertSame(25.0, $seenOptions['timeout']);
        self::assertSame(50.0, $seenOptions['max_duration']);
    }

    public function testASearchPreviewAsksEachAlternative(): void
    {
        $rss = 'GET https://lemmy.example/api/v3/search?q=rss&type_=Posts&sort=New&listing_type=All&limit=50';
        $atom = 'GET https://lemmy.example/api/v3/search?q=atom&type_=Posts&sort=New&listing_type=All&limit=50';
        $http = new RoutedHttpClient([
            $rss => self::lemmyAnswer([
                self::lemmyPost(1, 'rss news', '2026-11-14T05:00:00Z'),
            ]),
            $atom => self::lemmyAnswer([
                self::lemmyPost(1, 'rss news', '2026-11-14T05:00:00Z'),
                self::lemmyPost(2, 'atom feeds', '2026-11-14T06:00:00Z'),
            ]),
        ]);

        $page = $this->source($http->client())->fetchNewest(SocialApiWiring::lemmyAccount(), 'rss || atom');

        self::assertSame([$rss, $atom], $http->requestLines());
        self::assertSame(
            ['https://feddit.example/post/2', 'https://feddit.example/post/1'],
            array_map(static fn (ParsedEntryModel $entry): string => $entry->guid, $page->parsed->entries),
        );
    }

    public function testASearchPreviewChecksAPhrase(): void
    {
        $http = new RoutedHttpClient([
            self::LEMMY_SEARCH => self::lemmyAnswer([
                self::lemmyPost(1, 'the rss reader', '2026-11-14T05:00:00Z'),
                self::lemmyPost(2, 'reader for rss', '2026-11-14T06:00:00Z'),
            ]),
        ]);

        $page = $this->source($http->client())->fetchNewest(SocialApiWiring::lemmyAccount(), '"rss reader"');

        self::assertSame([self::LEMMY_SEARCH], $http->requestLines());
        self::assertSame(
            ['https://feddit.example/post/1'],
            array_map(static fn (ParsedEntryModel $entry): string => $entry->guid, $page->parsed->entries),
        );
    }

    public function testASearchPreviewWithoutATermIsTooBroad(): void
    {
        $http = new RoutedHttpClient([]);

        try {
            $this->source($http->client())->fetchNewest(SocialApiWiring::lemmyAccount(), '-gazette');
            self::fail('Lemmy needs a word to search for.');
        } catch (SearchQueryTooBroadException) {
            self::assertSame([], $http->requestLines());
        }
    }

    public function testBuildRequestsAsksEachAlternativeInTermOrder(): void
    {
        $requests = $this->source((new RoutedHttpClient([]))->client())->buildRequests(
            SocialApiWiring::lemmyAccount(),
            'rss || atom',
        );

        self::assertCount(2, $requests);
        self::assertStringContainsString('q=rss&', $requests[0]->url);
        self::assertStringContainsString('q=atom&', $requests[1]->url);
    }

    private function source(HttpClientInterface $http): LemmySearchSource
    {
        return SocialApiWiring::lemmySearchSource($http, SocialApiWiring::searchAnswers($http, new ArrayAdapter()));
    }

    /** @param list<array<string, mixed>> $posts */
    private static function lemmyAnswer(array $posts): MockResponse
    {
        return self::json(['type_' => 'Posts', 'comments' => [], 'posts' => $posts]);
    }

    /** @return array<string, mixed> */
    private static function lemmyPost(int $id, string $name, string $published): array
    {
        return [
            'post' => [
                'id' => $id,
                'name' => $name,
                'url' => null,
                'creator_id' => 9,
                'community_id' => 5,
                'removed' => false,
                'deleted' => false,
                'published' => $published,
                'ap_id' => 'https://feddit.example/post/' . $id,
            ],
            'creator' => [
                'id' => 9,
                'name' => 'ada',
                'display_name' => 'Ada',
                'actor_id' => 'https://feddit.example/u/ada',
            ],
            'community' => [
                'id' => 5,
                'name' => 'rss',
                'title' => 'RSS',
                'actor_id' => 'https://feddit.example/c/rss',
            ],
            'counts' => ['post_id' => $id, 'comments' => 3, 'score' => 12, 'upvotes' => 12, 'downvotes' => 0],
        ];
    }

    /** @param array<mixed> $body */
    private static function json(array $body, int $status = Response::HTTP_OK): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }
}
