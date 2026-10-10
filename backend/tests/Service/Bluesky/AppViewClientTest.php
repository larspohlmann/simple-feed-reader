<?php

declare(strict_types=1);

namespace App\Tests\Service\Bluesky;

use App\Service\Bluesky\AppViewClient;
use App\Service\Bluesky\Exception\AppViewAnswerException;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\HostThrottle;
use App\Tests\Support\Bluesky;
use App\Tests\Support\ReadsFixtures;
use App\Tests\Support\StubFeedFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class AppViewClientTest extends TestCase
{
    use ReadsFixtures;

    private const string TISCH = 'at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxjuesq6v62t';
    private const string GONE = 'at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxgone';
    private const string TISCH_QUERY = 'uris=at%3A%2F%2Fdid%3Aplc%3Aqobvnkudcv3zlaklxxjduqoi%2Fapp.bsky.feed.post'
        . '%2F3mxjuesq6v62t';
    private const string GONE_QUERY = 'uris=at%3A%2F%2Fdid%3Aplc%3Aqobvnkudcv3zlaklxxjduqoi%2Fapp.bsky.feed.post'
        . '%2F3mxgone';

    private StubFeedFetcher $fetcher;
    private HostThrottle $throttle;

    protected function setUp(): void
    {
        $clock = new MockClock('2026-10-10 12:00:00', 'UTC');
        $this->fetcher = new StubFeedFetcher();
        $this->throttle = new HostThrottle(new ArrayAdapter(clock: $clock), $clock);
    }

    public function testAsksForEveryUriInOneRequestAndKeysTheAnswerByUri(): void
    {
        $url = Bluesky::GET_POSTS . '?' . self::TISCH_QUERY . '&' . self::GONE_QUERY;
        $this->fetcher->willReturnBody($url, $this->fixture('Bluesky/external.json'));

        $posts = $this->client()->posts([self::TISCH, self::GONE]);

        self::assertSame([$url], $this->fetcher->fetchedUrls);
        self::assertSame([self::TISCH], array_keys($posts));
        self::assertSame('Mother Jones', $posts[self::TISCH]->node('author')->string('displayName'));
    }

    public function testAPostWithoutAUriIsLeftOut(): void
    {
        $url = Bluesky::GET_POSTS . '?' . self::TISCH_QUERY;
        $this->fetcher->willReturnBody($url, '{"posts":[{"cid":"bafy"},{"uri":"' . self::TISCH . '"}]}');

        self::assertSame([self::TISCH], array_keys($this->client()->posts([self::TISCH])));
    }

    public function testAThrottledAnswerIsRecordedForTheHostAndRethrown(): void
    {
        $this->fetcher->willThrow(
            Bluesky::GET_POSTS . '?' . self::TISCH_QUERY,
            new FeedThrottledException('HTTP 429', 300),
        );
        $client = $this->client();
        self::assertFalse($client->isThrottled());

        try {
            $client->posts([self::TISCH]);
            self::fail('A throttled answer must be rethrown.');
        } catch (FeedThrottledException) {
        }

        self::assertTrue($client->isThrottled());
        self::assertSame(300, $this->throttle->remainingSeconds(Bluesky::GET_POSTS));
    }

    public function testAnotherFetchFailureRecordsNoThrottle(): void
    {
        $this->fetcher->willThrow(
            Bluesky::GET_POSTS . '?' . self::TISCH_QUERY,
            new FeedUnreachableException('timeout'),
        );
        $client = $this->client();

        try {
            $client->posts([self::TISCH]);
            self::fail('A fetch failure must be rethrown.');
        } catch (FeedUnreachableException) {
        }

        self::assertFalse($client->isThrottled());
    }

    /** @return iterable<string, array{string}> */
    public static function unusableAnswers(): iterable
    {
        yield 'not JSON' => ['<html>Bad gateway</html>'];
        yield 'an error object' => ['{"error":"InvalidRequest","message":"Missing required key \"uris\""}'];
        yield 'posts that are not a list' => ['{"posts":{"first":{"uri":"at://x"}}}'];
    }

    #[DataProvider('unusableAnswers')]
    public function testAnAnswerWithoutAPostsListIsRejected(string $body): void
    {
        $this->fetcher->willReturnBody(Bluesky::GET_POSTS . '?' . self::TISCH_QUERY, $body);

        $this->expectException(AppViewAnswerException::class);

        $this->client()->posts([self::TISCH]);
    }

    private function client(): AppViewClient
    {
        return new AppViewClient($this->fetcher, $this->throttle);
    }
}
