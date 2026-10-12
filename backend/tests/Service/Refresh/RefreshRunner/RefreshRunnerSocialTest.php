<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\RefreshRunner;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Refresh\Model\RefreshRequestModel;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Support\MastodonTimelinePage;
use App\Tests\DbTestCase;
use App\Tests\Support\PrivateFeedsWiring;
use App\Tests\Support\RefreshRunners;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StubFeedFetcher;
use App\Tests\Support\StubTimelineReader;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RefreshRunnerSocialTest extends DbTestCase
{
    private const string RSS_URL = 'https://example.com/feed.xml';

    public function testAPrivateFeedIsReadThroughItsAccountAndNeverFetchedByUrl(): void
    {
        $clock = new MockClock('2026-10-12 12:00:00', 'UTC');
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new UserFactory($this->entityManager, $hasher))->create('refresh-social@example.test');
        $account = SocialAccounts::mastodonOf($user);
        $rss = new Feed(self::RSS_URL);
        $this->entityManager->persist($account);
        $this->entityManager->persist($rss);
        $this->entityManager->persist(new Subscription($user, $rss, $clock->now()));
        $this->entityManager->flush();
        PrivateFeedsWiring::build(self::getContainer(), $this->entityManager, $clock)
            ->create($account, PrivateFeedModel::home());
        $homeUrl = sprintf('social:mastodon:%d:home', $account->requireId());
        $fetcher = new StubFeedFetcher($clock);
        $fetcher->willReturnBody(self::RSS_URL, /** @lang TEXT */ <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"><channel><title>Example</title>
            <item><title>Post</title><link>https://example.com/p</link><guid>p-1</guid></item>
            </channel></rss>
            XML);
        $homepages = new StubFeedFetcher();
        foreach (['https://example.com', 'https://mastodon.example'] as $origin) {
            $homepages->willReturn(
                $origin,
                FetchResponseModel::fetched($origin, false, '<html lang="en"></html>', null, null),
            );
        }
        $reader = new StubTimelineReader([$homeUrl => MastodonTimelinePage::of([JsonNodeModel::of([
            'id' => '109',
            'uri' => 'https://mastodon.example/users/bob/statuses/109',
            'url' => 'https://mastodon.example/@bob/109',
            'created_at' => '2026-10-12T11:59:00Z',
            'content' => '<p>Hello</p>',
            'account' => ['acct' => 'bob', 'display_name' => 'Bob', 'url' => 'https://mastodon.example/@bob'],
        ])])]);

        $report = RefreshRunners::fromContainer(self::getContainer(), $this->entityManager, $clock)
            ->readingTimelinesFrom($reader)
            ->build($fetcher, $homepages)
            ->run(RefreshRequestModel::allDue(300));

        self::assertSame(2, $report->total);
        self::assertSame(2, $report->fetched);
        self::assertSame([self::RSS_URL], $fetcher->fetchedUrls);
        self::assertCount(1, $reader->asked);
        $home = $this->entityManager->getRepository(Feed::class)->findOneBy(['url' => $homeUrl]);
        self::assertNotNull($home);
        self::assertSame(1, $this->entityManager->getRepository(Entry::class)->count(['feed' => $home]));
    }

    public function testAThrottledAccountIsAskedOnceAPass(): void
    {
        $clock = new MockClock('2026-10-12 12:00:00', 'UTC');
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new UserFactory($this->entityManager, $hasher))->create('refresh-social-throttled@example.test');
        $account = SocialAccounts::mastodonOf($user);
        $this->entityManager->persist($account);
        $this->entityManager->flush();
        $privateFeeds = PrivateFeedsWiring::build(self::getContainer(), $this->entityManager, $clock);
        $privateFeeds->create($account, PrivateFeedModel::home());
        $privateFeeds->create($account, PrivateFeedModel::userList('42', 'Friends'));
        $throttled = new SocialNetworkThrottledException('The Mastodon instance asked for fewer requests.', 120);
        $reader = new StubTimelineReader([
            sprintf('social:mastodon:%d:home', $account->requireId()) => $throttled,
            sprintf('social:mastodon:%d:list:42', $account->requireId()) => $throttled,
        ]);
        $homepages = new StubFeedFetcher();
        $homepages->willReturn(
            'https://mastodon.example',
            FetchResponseModel::fetched('https://mastodon.example', false, '<html lang="en"></html>', null, null),
        );

        $report = RefreshRunners::fromContainer(self::getContainer(), $this->entityManager, $clock)
            ->readingTimelinesFrom($reader)
            ->build(new StubFeedFetcher($clock), $homepages)
            ->run(RefreshRequestModel::allDue(300));

        self::assertSame(2, $report->throttled);
        self::assertCount(1, $reader->asked);
    }
}
