<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\User;
use App\Repository\SocialAccountRepository;
use App\Service\Social\AuthorFeeds;
use App\Service\Social\Exception\BlueskyProfileHiddenException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\HiddenProfileSubscribeRoute;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Subscription\Model\SubscribeRequestModel;
use App\Tests\DbTestCase;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\StubHiddenProfiles;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class HiddenProfileSubscribeRouteTest extends DbTestCase
{
    private const string RUSTY = 'did:plc:hczfd45prvqtdrw77256syag';
    private const string PAGE = 'https://bsky.app/profile/rusty-17.bsky.social';

    private User $user;
    private StubHiddenProfiles $hiddenProfiles;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('hidden-route@example.test');
        $this->hiddenProfiles = new StubHiddenProfiles();
        $this->hiddenProfiles->hide(new HiddenProfileModel(self::RUSTY, 'Rusty'), 'rusty-17.bsky.social');
        $this->logger = new RecordingLogger();
    }

    public function testAHiddenProfileIsSubscribedAsAnAuthorFeedOfTheDefaultAccount(): void
    {
        $account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($this->user));

        $outcome = $this->route()->subscribe(new SubscribeRequestModel($this->user, self::PAGE . '/rss', []));

        self::assertSame(
            sprintf('social:bluesky:%d:author:%s', $account->requireId(), self::RUSTY),
            $outcome?->subscription?->getFeed()->getUrl(),
        );
        self::assertSame([['rusty-17.bsky.social']], $this->hiddenProfiles->asked);
    }

    public function testAFeedUrlNamingTheProfilesDidIsAskedAboutByThatDid(): void
    {
        $account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($this->user));
        $url = 'https://bsky.app/profile/' . self::RUSTY . '/rss';

        $outcome = $this->route()->subscribe(new SubscribeRequestModel($this->user, $url, []));

        self::assertSame(
            sprintf('social:bluesky:%d:author:%s', $account->requireId(), self::RUSTY),
            $outcome?->subscription?->getFeed()->getUrl(),
        );
        self::assertSame([[self::RUSTY]], $this->hiddenProfiles->asked);
    }

    public function testAHiddenProfileWithoutAConnectedAccountIsRefusedWithTheWayOut(): void
    {
        $this->expectException(BlueskyProfileHiddenException::class);
        $this->expectExceptionMessage(
            'This profile is only visible to logged-in Bluesky users. Connect a Bluesky account in Settings → Social'
            . ' Media to subscribe.',
        );

        $this->route()->subscribe(new SubscribeRequestModel($this->user, self::PAGE, []));
    }

    public function testAnAccountThatNeedsAReconnectCannotReadTheProfile(): void
    {
        $account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($this->user));
        $account->markNeedsReconnect();
        $this->entityManager->flush();

        $this->expectException(BlueskyProfileHiddenException::class);
        $this->route()->subscribe(new SubscribeRequestModel($this->user, self::PAGE, []));
    }

    public function testAPublicProfileIsLeftToDiscovery(): void
    {
        self::assertNull(
            $this->route()->subscribe(
                new SubscribeRequestModel($this->user, 'https://bsky.app/profile/bob.bsky.social', []),
            ),
        );
    }

    public function testAnyOtherUrlIsLeftToDiscoveryUnasked(): void
    {
        self::assertNull(
            $this->route()->subscribe(new SubscribeRequestModel($this->user, 'https://example.com/feed', [])),
        );
        self::assertSame([], $this->hiddenProfiles->asked);
    }

    public function testAnUnansweredLabelCheckIsLeftToDiscoveryAndLogged(): void
    {
        $this->hiddenProfiles->failure = new SocialNetworkUnreachableException('down');

        self::assertNull($this->route()->subscribe(new SubscribeRequestModel($this->user, self::PAGE, [])));
        self::assertSame('warning', $this->logger->records[0]['level'] ?? null);
    }

    private function route(): HiddenProfileSubscribeRoute
    {
        $socialAccounts = self::getContainer()->get(SocialAccountRepository::class);
        self::assertInstanceOf(SocialAccountRepository::class, $socialAccounts);
        $authorFeeds = self::getContainer()->get(AuthorFeeds::class);
        self::assertInstanceOf(AuthorFeeds::class, $authorFeeds);

        return new HiddenProfileSubscribeRoute($this->hiddenProfiles, $socialAccounts, $authorFeeds, $this->logger);
    }
}
