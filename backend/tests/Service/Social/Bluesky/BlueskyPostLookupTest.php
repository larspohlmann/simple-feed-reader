<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Bluesky;

use App\Service\Social\Bluesky\BlueskyPostLookup;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BlueskyPostLookupTest extends TestCase
{
    public function testKeysPostsByUriInChunksOfTwentyFive(): void
    {
        $http = RoutedHttpClient::answering(static fn (string $method, string $url): MockResponse => match (true) {
            str_contains($url, 'createSession') => SocialApiWiring::blueskySessionAnswer('jwt'),
            default => new MockResponse((string) json_encode(['posts' => [
                ['uri' => 'at://did:plc:alice/app.bsky.feed.post/' . (str_contains($url, '3l26') ? '3l26' : '3l1'),
                    'record' => ['text' => 'a post']],
            ]])),
        });
        $uris = array_map(
            static fn (int $number): string => 'at://did:plc:alice/app.bsky.feed.post/3l' . $number,
            range(1, 26),
        );

        $posts = (new BlueskyPostLookup(SocialApiWiring::blueskySessions($http->client())))
            ->byUri(SocialApiWiring::blueskyAccount(), $uris);

        $lookups = array_values(array_filter(
            $http->requestedUrls(),
            static fn (string $url): bool => str_contains($url, 'getPosts'),
        ));
        self::assertCount(2, $lookups);
        self::assertSame(25, substr_count($lookups[0], 'uris='));
        self::assertSame(1, substr_count($lookups[1], 'uris='));
        self::assertSame(
            ['at://did:plc:alice/app.bsky.feed.post/3l1', 'at://did:plc:alice/app.bsky.feed.post/3l26'],
            array_keys($posts),
        );
        self::assertSame('a post', $posts['at://did:plc:alice/app.bsky.feed.post/3l26']->node('record')->text('text'));
    }
}
