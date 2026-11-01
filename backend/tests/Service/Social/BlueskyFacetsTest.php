<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Social\BlueskyFacets;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class BlueskyFacetsTest extends TestCase
{
    private const string RESOLVE = 'GET https://public.api.bsky.app/xrpc/com.atproto.identity.resolveHandle?handle=';

    public function testLinksTagsAndResolvedMentionsBecomeFacets(): void
    {
        $http = new RoutedHttpClient([self::RESOLVE . 'ann.bsky.social' => new MockResponse('{"did":"did:plc:ann"}')]);

        $facets = $this->facets($http)->of('@ann.bsky.social https://x.test #news');

        self::assertSame([
            [
                'index' => ['byteStart' => 17, 'byteEnd' => 31],
                'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://x.test']],
            ],
            [
                'index' => ['byteStart' => 32, 'byteEnd' => 37],
                'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => 'news']],
            ],
            [
                'index' => ['byteStart' => 0, 'byteEnd' => 16],
                'features' => [['$type' => 'app.bsky.richtext.facet#mention', 'did' => 'did:plc:ann']],
            ],
        ], $facets);
    }

    public function testAHandleThatDoesNotResolveStaysPlainText(): void
    {
        $http = new RoutedHttpClient([
            self::RESOLVE . 'nobody.test' => new MockResponse('{"error":"InvalidRequest"}', [
                'http_code' => Response::HTTP_BAD_REQUEST,
            ]),
            self::RESOLVE . 'down.test' => new MockResponse('', ['http_code' => Response::HTTP_BAD_GATEWAY]),
        ]);

        self::assertSame([], $this->facets($http)->of('@nobody.test and @down.test'));
    }

    public function testAHandleIsAskedOnceAndAnUnreachableNetworkStopsTheAsking(): void
    {
        $http = new RoutedHttpClient([
            self::RESOLVE . 'down.test' => new MockResponse('', ['http_code' => Response::HTTP_BAD_GATEWAY]),
        ]);

        self::assertSame([], $this->facets($http)->of('@down.test @down.test @later.test'));
        self::assertCount(1, $http->requests);
    }

    public function testAPlainTextAsksNothing(): void
    {
        $http = new RoutedHttpClient([]);

        self::assertSame([], $this->facets($http)->of('just words'));
        self::assertSame([], $http->requests);
    }

    private function facets(RoutedHttpClient $http): BlueskyFacets
    {
        return new BlueskyFacets(SocialApiWiring::blueskyIdentityResolver($http->client()));
    }
}
