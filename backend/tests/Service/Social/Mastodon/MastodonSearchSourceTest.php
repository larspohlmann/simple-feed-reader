<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Mastodon;

use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Social\Social\Exception\SearchQueryTooBroadException;
use App\Service\Social\Mastodon\MastodonPrivateFeedRule;
use App\Service\Social\Mastodon\MastodonSearchSource;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class MastodonSearchSourceTest extends TestCase
{
    private const string MASTODON_SEARCH = 'GET https://mastodon.example/api/v2/search'
        . '?type=statuses&resolve=false&limit=40&q=rss%20reader';

    public function testASearchAsksForTheLatestStatusesWithoutAnyLowerBound(): void
    {
        $http = new RoutedHttpClient([self::MASTODON_SEARCH => self::json(['statuses' => [self::mastodonStatus()]])]);

        $page = $this->source($http->client())->fetchNewest(SocialApiWiring::mastodonAccount(), 'rss reader');

        self::assertCount(1, $page->parsed->entries);
        self::assertSame([self::MASTODON_SEARCH], $http->requestLines());
        self::assertContains('Authorization: Bearer ' . SocialAccounts::ACCESS_TOKEN, $http->requests[0]['headers']);
    }

    public function testAPreviewAsksOncePerAlternativeAndMergesTheAnswers(): void
    {
        $rss = 'GET https://mastodon.example/api/v2/search?type=statuses&resolve=false&limit=40&q=rss';
        $atom = 'GET https://mastodon.example/api/v2/search?type=statuses&resolve=false&limit=40&q=atom';
        $http = new RoutedHttpClient([
            $rss => self::json(['statuses' => [self::mastodonStatus('1')]]),
            $atom => self::json(['statuses' => [
                self::mastodonStatus('1'),
                self::mastodonStatus('2', '<p>atom feeds</p>'),
            ]]),
        ]);

        $page = $this->source($http->client())->fetchNewest(SocialApiWiring::mastodonAccount(), '(rss || atom)');

        self::assertCount(2, $page->parsed->entries);
        self::assertSame([$rss, $atom], $http->requestLines());
    }

    public function testAPreviewListsTheMergedAnswersNewestFirst(): void
    {
        $rss = 'GET https://mastodon.example/api/v2/search?type=statuses&resolve=false&limit=40&q=rss';
        $atom = 'GET https://mastodon.example/api/v2/search?type=statuses&resolve=false&limit=40&q=atom';
        $http = new RoutedHttpClient([
            $rss => self::json(['statuses' => [self::mastodonStatus('110'), self::mastodonStatus('108')]]),
            $atom => self::json(['statuses' => [
                self::mastodonStatus('111', '<p>atom feeds</p>'),
                self::mastodonStatus('109', '<p>atom feeds</p>'),
            ]]),
        ]);

        $page = $this->source($http->client())->fetchNewest(SocialApiWiring::mastodonAccount(), '(rss || atom)');

        self::assertSame(
            [
                'https://mastodon.example/@bob/111',
                'https://mastodon.example/@bob/110',
                'https://mastodon.example/@bob/109',
                'https://mastodon.example/@bob/108',
            ],
            array_map(static fn (ParsedEntryModel $entry): ?string => $entry->url, $page->parsed->entries),
        );
    }

    public function testAPreviewTooBroadIsRefusedBeforeAnyRequest(): void
    {
        $http = new RoutedHttpClient([]);

        try {
            $this->source($http->client())->fetchNewest(
                SocialApiWiring::mastodonAccount(),
                '(a || b || c) (d || e || f)',
            );
            self::fail('Nine alternatives are more than Mastodon is asked for.');
        } catch (SearchQueryTooBroadException) {
            self::assertSame([], $http->requestLines());
        }
    }

    private function source(HttpClientInterface $http): MastodonSearchSource
    {
        return new MastodonSearchSource(
            SocialApiWiring::mastodonAccountApi($http),
            new MastodonPrivateFeedRule(),
            SocialApiWiring::searchAnswers($http, new ArrayAdapter()),
        );
    }

    /** @return array<string, mixed> */
    private static function mastodonStatus(string $id = '1', string $content = '<p>Which RSS reader?</p>'): array
    {
        return [
            'id' => $id,
            'uri' => 'https://mastodon.example/users/bob/statuses/' . $id,
            'url' => 'https://mastodon.example/@bob/' . $id,
            'created_at' => '2026-10-12T10:00:00Z',
            'content' => $content,
            'in_reply_to_id' => null,
            'account' => ['acct' => 'bob', 'display_name' => 'Bob', 'url' => 'https://mastodon.example/@bob'],
        ];
    }

    /** @param array<mixed> $body */
    private static function json(array $body, int $status = Response::HTTP_OK): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }
}
