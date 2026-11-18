<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\ProfilePictureSource;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\FeedRepository;
use App\Service\Outbound\Fetch\IpValidator;
use App\Service\Outbound\Fetch\UrlGuard;
use App\Service\Social\Social\LemmyApiClient;
use App\Service\Social\Social\Model\ProfileCallBudgetModel;
use App\Service\Social\Social\Model\ProfileImageSweepReportModel;
use App\Service\Social\Social\ProfilePictureSource\LemmyProfilePictures;
use App\Tests\DbTestCase;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\StaticDnsResolver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class LemmyProfilePicturesTest extends DbTestCase
{
    private const string NOW = '2026-11-18 12:00:00';
    private const string COMMUNITY_API = 'https://lemmy.world/api/v3/community?name=technology';
    private const string USER_API = 'https://lemmy.world/api/v3/user?username=lars_pohlmann&limit=1';

    /** @var array<string, MockResponse> */
    private array $routes = [];
    private ?RoutedHttpClient $http = null;
    private ?User $subscriber = null;

    public function testACommunityFeedTakesItsCommunityIcon(): void
    {
        $feed = $this->lemmyFeed('https://lemmy.world/feeds/c/technology.xml?sort=New');
        $this->answer(self::COMMUNITY_API, [
            'community_view' => ['community' => ['icon' => 'https://lemmy.world/pictrs/image/c.png']],
        ]);

        $report = $this->refresh(5);

        self::assertSame('https://lemmy.world/pictrs/image/c.png', $feed->getImageUrl());
        self::assertSame(self::NOW, $feed->getImageCheckedAt()?->format('Y-m-d H:i:s'));
        self::assertSame([1, 1, 1], [$report->checked, $report->changed, $report->calls]);
    }

    public function testACommunityWithoutAnIconHasNoPicture(): void
    {
        $feed = $this->lemmyFeed('https://lemmy.world/feeds/c/technology.xml');
        $this->answer(self::COMMUNITY_API, ['community_view' => ['community' => ['name' => 'technology']]]);

        $this->refresh(5);

        self::assertNull($feed->getImageUrl());
        self::assertNotNull($feed->getImageCheckedAt());
    }

    public function testAUserFeedTakesTheUsersAvatar(): void
    {
        $feed = $this->lemmyFeed('https://lemmy.world/feeds/u/lars_pohlmann.xml');
        $this->answer(self::USER_API, [
            'person_view' => ['person' => ['avatar' => 'https://lemmy.world/pictrs/image/a.png']],
        ]);

        $this->refresh(5);

        self::assertSame('https://lemmy.world/pictrs/image/a.png', $feed->getImageUrl());
    }

    public function testAUserWithoutAnAvatarGetsTheLemmyMouse(): void
    {
        $feed = $this->lemmyFeed('https://lemmy.world/feeds/u/lars_pohlmann.xml');
        $this->answer(self::USER_API, ['person_view' => ['person' => ['avatar' => null]]]);

        $this->refresh(5);

        self::assertSame(LemmyProfilePictures::MOUSE, $feed->getImageUrl());
    }

    public function testAnUnknownActorKeepsItsPicture(): void
    {
        $feed = $this->lemmyFeed('https://lemmy.world/feeds/c/technology.xml', 'https://lemmy.world/old.png');
        $this->answerStatus(self::COMMUNITY_API, Response::HTTP_NOT_FOUND, 'couldnt_find_community');

        $report = $this->refresh(5);

        self::assertSame('https://lemmy.world/old.png', $feed->getImageUrl());
        self::assertNotNull($feed->getImageCheckedAt());
        self::assertSame(0, $report->changed);
    }

    public function testAnUnreachableInstanceIsSetAsideAndTheNextFeedStillSwept(): void
    {
        $down = $this->lemmyFeed('https://feddit.org/feeds/c/linux.xml', 'https://feddit.org/old.png');
        $up = $this->lemmyFeed('https://lemmy.world/feeds/c/technology.xml');
        $this->answerStatus('https://feddit.org/api/v3/community?name=linux', Response::HTTP_SERVICE_UNAVAILABLE, '');
        $this->answer(self::COMMUNITY_API, [
            'community_view' => ['community' => ['icon' => 'https://lemmy.world/pictrs/image/c.png']],
        ]);
        $logger = new RecordingLogger();

        $report = $this->refresh(5, $logger);

        self::assertSame('https://feddit.org/old.png', $down->getImageUrl());
        self::assertNotNull($down->getImageCheckedAt());
        self::assertSame('https://lemmy.world/pictrs/image/c.png', $up->getImageUrl());
        self::assertSame([2, 2], [$report->checked, $report->calls]);
        self::assertSame(['warning'], array_column($logger->records, 'level'));
    }

    public function testTheBudgetCapsTheFeedsAsked(): void
    {
        foreach (['a', 'b', 'c'] as $name) {
            $this->lemmyFeed('https://lemmy.world/feeds/c/' . $name . '.xml');
            $this->answer(
                'https://lemmy.world/api/v3/community?name=' . $name,
                ['community_view' => ['community' => ['name' => 'x']]],
            );
        }

        $report = $this->refresh(2);

        self::assertSame(2, $report->calls);
        self::assertCount(2, $this->requestedUrls());
    }

    public function testAnAnswerWithoutTheViewKeepsThePicture(): void
    {
        $feed = $this->lemmyFeed('https://lemmy.world/feeds/c/technology.xml', 'https://lemmy.world/old.png');
        $this->answer(self::COMMUNITY_API, []);
        $logger = new RecordingLogger();

        $report = $this->refresh(5, $logger);

        self::assertSame('https://lemmy.world/old.png', $feed->getImageUrl());
        self::assertNotNull($feed->getImageCheckedAt());
        self::assertSame(0, $report->changed);
        self::assertSame(['warning'], array_column($logger->records, 'level'));
    }

    public function testAUserWithAnInsecureAvatarHasNoPicture(): void
    {
        $feed = $this->lemmyFeed('https://lemmy.world/feeds/u/lars_pohlmann.xml', 'https://lemmy.world/old.png');
        $this->answer(self::USER_API, [
            'person_view' => ['person' => ['avatar' => 'http://lemmy.world/a.png']],
        ]);

        $this->refresh(5);

        self::assertNull($feed->getImageUrl());
    }

    public function testAnInsecureIconIsNoPicture(): void
    {
        $feed = $this->lemmyFeed('https://lemmy.world/feeds/c/technology.xml');
        $this->answer(self::COMMUNITY_API, [
            'community_view' => ['community' => ['icon' => 'http://lemmy.world/c.png']],
        ]);

        $this->refresh(5);

        self::assertNull($feed->getImageUrl());
    }

    public function testAUserAndACommunityOfOneNameAskDifferentEndpoints(): void
    {
        $this->lemmyFeed('https://lemmy.world/feeds/u/ada.xml');
        $this->lemmyFeed('https://lemmy.world/feeds/c/ada.xml');
        $this->answer(
            'https://lemmy.world/api/v3/user?username=ada&limit=1',
            ['person_view' => ['person' => ['name' => 'ada']]],
        );
        $this->answer(
            'https://lemmy.world/api/v3/community?name=ada',
            ['community_view' => ['community' => ['name' => 'x']]],
        );

        $this->refresh(5);

        self::assertEqualsCanonicalizing(
            ['https://lemmy.world/api/v3/user?username=ada&limit=1', 'https://lemmy.world/api/v3/community?name=ada'],
            $this->requestedUrls(),
        );
    }

    public function testAFederatedCommunityAsksItsOwnInstance(): void
    {
        $this->lemmyFeed('https://feddit.org/feeds/c/linux@lemmy.ml.xml');
        $this->answer(
            'https://feddit.org/api/v3/community?name=linux%40lemmy.ml',
            ['community_view' => ['community' => ['name' => 'linux']]],
        );

        $this->refresh(5);

        self::assertSame(['https://feddit.org/api/v3/community?name=linux%40lemmy.ml'], $this->requestedUrls());
    }

    /** @param array<string, mixed> $body */
    private function answer(string $url, array $body): void
    {
        $this->routes['GET ' . $url] = new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function answerStatus(string $url, int $status, string $errorCode): void
    {
        $body = '' === $errorCode ? '' : json_encode(['error' => $errorCode], \JSON_THROW_ON_ERROR);
        $this->routes['GET ' . $url] = new MockResponse($body, ['http_code' => $status]);
    }

    /** @return list<string> */
    private function requestedUrls(): array
    {
        return $this->http?->requestedUrls() ?? [];
    }

    private function refresh(int $calls, LoggerInterface $logger = new NullLogger()): ProfileImageSweepReportModel
    {
        $feeds = $this->entityManager->getRepository(Feed::class);
        self::assertInstanceOf(FeedRepository::class, $feeds);
        $this->http = new RoutedHttpClient($this->routes);
        $guard = new UrlGuard(
            new StaticDnsResolver(['lemmy.world' => ['93.184.216.34'], 'feddit.org' => ['93.184.216.35']]),
            new IpValidator(),
        );
        $lemmy = new LemmyApiClient(FetchWiring::patientApiClient($this->http->client(), $guard));

        $report = (new LemmyProfilePictures($feeds, $lemmy, $logger))->refresh(
            ProfileCallBudgetModel::calls($calls),
            new \DateTimeImmutable(self::NOW),
        );
        $this->entityManager->flush();

        return $report;
    }

    private function lemmyFeed(string $url, ?string $imageUrl = null): Feed
    {
        $feed = new Feed($url);
        $feed->setImageUrl($imageUrl);
        $this->subscriber ??= new User('lemmy-pictures@example.com', new \DateTimeImmutable(self::NOW));
        $this->entityManager->persist($this->subscriber);
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($this->subscriber, $feed, new \DateTimeImmutable(self::NOW)));
        $this->entityManager->flush();

        return $feed;
    }
}
