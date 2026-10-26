<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Tests\Support\ApiTestCase;
use App\Tests\Support\RoutesOutboundApis;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class EmbedDetailsControllerTest extends ApiTestCase
{
    use RoutesOutboundApis;

    private const array ROUTED_HOSTS = ['www.youtube.com', 'soundcloud.com'];
    private const string YOUTUBE_PLAYER = 'POST https://www.youtube.com/youtubei/v1/player?prettyPrint=false';
    private const string SOUNDCLOUD_OEMBED = 'GET https://soundcloud.com/oembed?format=json'
        . '&url=https%3A%2F%2Fapi.soundcloud.com%2Ftracks%2F123456';
    private const string VIDEO_PATH = '/api/youtube/videos/570hdDp8l4g';
    private const string TRACK_PATH = '/api/soundcloud/tracks/123456';
    private const string TRACKLIST = "0:00 Intro\n3:10 Two\n7:45 Three";
    private const string VIDEO_TITLE = 'Psytrance mix 570';
    private const string TRACK_TITLE = 'Catching Flies | Anjunadeep Open Air: London 2026 by Anjunadeep';

    /** Must match framework.rate_limiter.embed_details.limit in rate_limiter.yaml. */
    private const int DETAILS_BUDGET = 120;

    protected function setUp(): void
    {
        self::bootKernel();
        foreach (['test.cache.rate_limiter', 'embed.details.cache'] as $poolId) {
            $pool = self::getContainer()->get($poolId);
            self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
            $pool->clear();
        }
        self::ensureKernelShutdown();
    }

    public function testAnAnonymousCallerIsRefusedOnBothRoutes(): void
    {
        $client = static::createClient();

        $client->request('GET', self::VIDEO_PATH);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $client->request('GET', self::TRACK_PATH);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAVideoAnswersItsTitleLengthAndChapters(): void
    {
        $client = $this->routedClient([self::YOUTUBE_PLAYER => self::video(self::TRACKLIST)]);
        $this->signIn($client, 'chapters-video@example.test');

        $client->request('GET', self::VIDEO_PATH);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['title' => self::VIDEO_TITLE, 'durationSeconds' => 4964, 'chapters' => self::CHAPTERS],
            $this->payload($client),
        );
        self::assertSame([self::YOUTUBE_PLAYER], $this->http->requestLines());
    }

    public function testATrackAnswersItsTitleAndChaptersButNoLength(): void
    {
        $client = $this->routedClient([self::SOUNDCLOUD_OEMBED => self::track(self::TRACKLIST)]);
        $this->signIn($client, 'chapters-track@example.test');

        $client->request('GET', self::TRACK_PATH);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['title' => self::TRACK_TITLE, 'durationSeconds' => null, 'chapters' => self::CHAPTERS],
            $this->payload($client),
        );
        self::assertSame([self::SOUNDCLOUD_OEMBED], $this->http->requestLines());
    }

    public function testASecondRequestIsAnsweredFromTheCache(): void
    {
        $client = $this->routedClient([self::YOUTUBE_PLAYER => self::video(self::TRACKLIST)]);
        $this->signIn($client, 'chapters-cached@example.test');

        $client->request('GET', self::VIDEO_PATH);
        $client->request('GET', self::VIDEO_PATH);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['title' => self::VIDEO_TITLE, 'durationSeconds' => 4964, 'chapters' => self::CHAPTERS],
            $this->payload($client),
        );
        self::assertSame([self::YOUTUBE_PLAYER], $this->http->requestLines());
    }

    public function testADescriptionWithoutChaptersGivesAnEmptyListAndKeepsEveryKey(): void
    {
        $client = $this->routedClient([self::YOUTUBE_PLAYER => self::video('Just a video.')]);
        $this->signIn($client, 'chapters-none@example.test');

        $client->request('GET', self::VIDEO_PATH);

        self::assertResponseIsSuccessful();
        self::assertSame(
            '{"title":"Psytrance mix 570","durationSeconds":4964,"chapters":[]}',
            $client->getResponse()->getContent(),
        );
    }

    public function testAProviderThatDoesNotAnswerIsServiceUnavailableAndAsksAgainNextTime(): void
    {
        $client = $this->routedClient([
            self::YOUTUBE_PLAYER => new MockResponse('{}', ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE]),
        ]);
        $this->signIn($client, 'chapters-down@example.test');

        $client->request('GET', self::VIDEO_PATH);
        $this->assertRejected($client, Response::HTTP_SERVICE_UNAVAILABLE);

        $client->request('GET', self::VIDEO_PATH);
        $this->assertRejected($client, Response::HTTP_SERVICE_UNAVAILABLE);
        self::assertSame([self::YOUTUBE_PLAYER, self::YOUTUBE_PLAYER], $this->http->requestLines());
    }

    public function testAnIdTheRouteRefusesReadsAsMissing(): void
    {
        $client = $this->routedClient([]);
        $this->signIn($client, 'chapters-bad-id@example.test');

        $client->request('GET', '/api/youtube/videos/short');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $client->request('GET', '/api/soundcloud/tracks/abc');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame([], $this->http->requests);
    }

    public function testTheOldChaptersPathsAreGone(): void
    {
        $client = $this->routedClient([]);
        $this->signIn($client, 'chapters-old-path@example.test');

        $client->request('GET', self::VIDEO_PATH . '/chapters');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $client->request('GET', self::TRACK_PATH . '/chapters');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame([], $this->http->requests);
    }

    public function testTheDetailsHaveTheirOwnBudget(): void
    {
        $client = $this->routedClient([self::YOUTUBE_PLAYER => self::video(self::TRACKLIST)]);
        $this->signIn($client, 'chapters-budget@example.test');
        for ($attempt = 0; $attempt < self::DETAILS_BUDGET; ++$attempt) {
            $client->request('GET', self::VIDEO_PATH);
            self::assertResponseIsSuccessful();
        }

        $client->request('GET', self::VIDEO_PATH);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    private const array CHAPTERS = [
        ['startSeconds' => 0, 'title' => 'Intro'],
        ['startSeconds' => 190, 'title' => 'Two'],
        ['startSeconds' => 465, 'title' => 'Three'],
    ];

    /** @param array<string, MockResponse> $routes */
    private function routedClient(array $routes): KernelBrowser
    {
        return $this->clientRouting(self::ROUTED_HOSTS, $routes);
    }

    private static function video(string $description): MockResponse
    {
        $video = ['title' => self::VIDEO_TITLE, 'lengthSeconds' => '4964', 'shortDescription' => $description];

        return new MockResponse(json_encode(['videoDetails' => $video], \JSON_THROW_ON_ERROR));
    }

    private static function track(string $description): MockResponse
    {
        return new MockResponse(
            json_encode(['title' => self::TRACK_TITLE, 'description' => $description], \JSON_THROW_ON_ERROR),
        );
    }
}
