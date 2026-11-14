<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\PostCountsSource;

use App\Service\Bluesky\AppViewClient;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\HostThrottle;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Model\SocialCountsModel;
use App\Service\Social\PostCountsSource\BlueskyAppViewCountsSource;
use App\Tests\Support\AssertsRefusal;
use App\Tests\Support\FakeAppView;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Response;

final class BlueskyAppViewCountsSourceTest extends TestCase
{
    use AssertsRefusal;

    private const string POST_URI = 'at://did:plc:bob/app.bsky.feed.post/3abc';

    private FakeAppView $appView;
    private BlueskyAppViewCountsSource $source;

    protected function setUp(): void
    {
        $this->appView = new FakeAppView();
        $clock = new MockClock('2026-10-13 10:00:00');
        $this->source = new BlueskyAppViewCountsSource(
            new AppViewClient($this->appView, new HostThrottle(new ArrayAdapter(clock: $clock), $clock)),
        );
    }

    public function testAPostsFourCountsComeFromThePublicAppView(): void
    {
        $this->appView->knowsPost([
            'uri' => self::POST_URI,
            'likeCount' => 12,
            'repostCount' => 3,
            'replyCount' => 4,
            'quoteCount' => 1,
        ]);

        self::assertEquals(new SocialCountsModel(12, 3, 4, 1), $this->source->countsOf(self::POST_URI));
        self::assertSame([[self::POST_URI]], $this->appView->requests);
    }

    public function testAPostAbsentFromTheAnswerIsNotFound(): void
    {
        $this->assertRefused(
            fn (): SocialCountsModel => $this->source->countsOf(self::POST_URI),
            SocialPostNotFoundException::class,
            'A post the AppView does not show must be gone.',
        );
    }

    public function testAPostViewWithoutItsCountsIsAnUnreachableNetwork(): void
    {
        $this->appView->knowsPost(['uri' => self::POST_URI, 'repostCount' => 3, 'replyCount' => 4]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->source->countsOf(self::POST_URI);
    }

    public function testAThrottlingAppViewIsAThrottledNetworkWithItsWait(): void
    {
        $this->appView->failsWith(new FeedThrottledException('Slow down.', 120));

        try {
            $this->source->countsOf(self::POST_URI);
            self::fail('A throttling AppView must reach the caller as a throttled network.');
        } catch (SocialNetworkThrottledException $exception) {
            self::assertSame(120, $exception->retryAfterSeconds);
        }
    }

    public function testAThrottledAppViewIsNotAskedAgainWhileTheWaitLasts(): void
    {
        $this->appView->failsWith(new FeedThrottledException('Slow down.', 120));
        $attempt = fn (): SocialCountsModel => $this->source->countsOf(self::POST_URI);
        $this->assertRefused($attempt, SocialNetworkThrottledException::class, 'The first ask must be throttled.');
        $this->appView->recovers();

        try {
            $attempt();
            self::fail('The wait must still hold.');
        } catch (SocialNetworkThrottledException $exception) {
            self::assertSame(120, $exception->retryAfterSeconds);
        }

        self::assertCount(1, $this->appView->requests);
    }

    public function testAnUnreachableAppViewIsAnUnreachableNetwork(): void
    {
        $this->appView->failsWith(new FeedUnreachableException('Down.', Response::HTTP_BAD_GATEWAY));

        try {
            $this->source->countsOf(self::POST_URI);
            self::fail('An unreachable AppView must reach the caller.');
        } catch (SocialNetworkUnreachableException $exception) {
            self::assertNotInstanceOf(SocialNetworkThrottledException::class, $exception);
        }
    }

    public function testAnAnswerWithoutAPostListIsAnUnreachableNetwork(): void
    {
        $this->appView->answersWithBody('{}');

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->source->countsOf(self::POST_URI);
    }
}
