<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Mastodon;

use App\Service\Social\Mastodon\MastodonStatusLimit;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class MastodonStatusLimitTest extends TestCase
{
    private const string INSTANCE = 'GET https://mastodon.example/api/v2/instance';

    public function testReadsTheInstancesLimit(): void
    {
        $http = new RoutedHttpClient([self::INSTANCE => new MockResponse(json_encode(
            ['configuration' => ['statuses' => ['max_characters' => 1000]]],
            \JSON_THROW_ON_ERROR,
        ))]);

        self::assertSame(1000, self::limit($http)->resolveMaxCharacters(SocialAccounts::mastodon()));
    }

    public function testAnInstanceThatDoesNotAnswerHoldsMastodonsDefault(): void
    {
        $http = new RoutedHttpClient([
            self::INSTANCE => new MockResponse('', ['http_code' => Response::HTTP_BAD_GATEWAY]),
        ]);

        self::assertSame(500, self::limit($http)->resolveMaxCharacters(SocialAccounts::mastodon()));
    }

    public function testAnAnswerWithoutALimitHoldsMastodonsDefault(): void
    {
        $http = new RoutedHttpClient([self::INSTANCE => new MockResponse('{"configuration":{}}')]);

        self::assertSame(500, self::limit($http)->resolveMaxCharacters(SocialAccounts::mastodon()));
    }

    private static function limit(RoutedHttpClient $http): MastodonStatusLimit
    {
        return SocialApiWiring::mastodonStatusLimit($http->client());
    }
}
