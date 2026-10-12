<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\SocialAccount;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialAccountStatus;
use App\Repository\SubscriptionRepository;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\BlueskyApiClient;
use App\Service\Social\BlueskyIdentityResolver;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\FollowMatcher;
use App\Service\Social\ImportCandidates;
use App\Service\Social\Model\FollowedAccountModel;
use App\Service\Social\Model\ImportCandidateModel;
use App\Service\Social\Model\NetworkCandidatesModel;
use App\Service\Social\Model\SocialCollectionModel;
use App\Service\Social\SocialCandidates\SocialCandidatesInterface;
use App\Tests\DbTestCase;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\ProfileFeedSubscriptions;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use App\Tests\Support\StubSocialCandidates;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ImportCandidatesTest extends DbTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('import-candidates@example.test');
    }

    private function importCandidates(SocialCandidatesInterface $network): ImportCandidates
    {
        $subscriptions = self::getContainer()->get(SubscriptionRepository::class);
        self::assertInstanceOf(SubscriptionRepository::class, $subscriptions);
        $guard = new UrlGuard(new StaticDnsResolver([]), new IpValidator());
        $resolver = new BlueskyIdentityResolver(
            new BlueskyApiClient(FetchWiring::guardedApiClient((new RoutedHttpClient([]))->client(), $guard)),
        );

        return new ImportCandidates($network, $subscriptions, new FollowMatcher($resolver), $this->entityManager);
    }

    private function account(): SocialAccount
    {
        $account = SocialAccounts::blueskyOf($this->user);
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    private function subscribe(string $feedUrl, ?string $feedTitle = null): Subscription
    {
        return (new ProfileFeedSubscriptions($this->entityManager))->subscribe($this->user, $feedUrl, $feedTitle);
    }

    private static function follow(string $did, string $handle): FollowedAccountModel
    {
        return new FollowedAccountModel($did, $handle, null, 'https://bsky.app/profile/' . $did . '/rss');
    }

    /**
     * @param list<ImportCandidateModel> $candidates
     *
     * @return list<array{string, string, ?int}>
     */
    private static function summary(array $candidates): array
    {
        return array_map(
            static fn (ImportCandidateModel $candidate): array => [
                $candidate->account->handle,
                $candidate->state->value,
                $candidate->subscriptionId,
            ],
            $candidates,
        );
    }

    public function testFollowsAreListedNewThenSubscribedThenNoLongerFollowedEachByHandle(): void
    {
        $account = $this->account();
        $bob = $this->subscribe('https://bsky.app/profile/did:plc:bob/rss');
        $gone = $this->subscribe('https://bsky.app/profile/did:plc:gone/rss', 'Gone Account');
        $this->subscribe('https://example.com/feed.xml');
        $network = new StubSocialCandidates(new NetworkCandidatesModel(
            [
                self::follow('did:plc:zed', '@Zed.bsky.social'),
                self::follow('did:plc:bob', '@bob.bsky.social'),
                self::follow('did:plc:amy', '@amy.bsky.social'),
            ],
            [],
            [],
            false,
        ));

        $follows = $this->importCandidates($network)->forAccount($account)->follows;

        self::assertSame(
            [
                ['@amy.bsky.social', 'new', null],
                ['@Zed.bsky.social', 'new', null],
                ['@bob.bsky.social', 'subscribed', $bob->requireId()],
                ['did:plc:gone', 'no_longer_followed', $gone->requireId()],
            ],
            self::summary($follows),
        );
        self::assertSame('Gone Account', $follows[3]->account->displayName);
    }

    public function testACutOffFollowListOffersNothingAsNoLongerFollowed(): void
    {
        $account = $this->account();
        $bob = $this->subscribe('https://bsky.app/profile/did:plc:bob/rss');
        $this->subscribe('https://bsky.app/profile/did:plc:beyond-the-cut/rss');
        $network = new StubSocialCandidates(new NetworkCandidatesModel(
            [self::follow('did:plc:bob', '@bob.bsky.social'), self::follow('did:plc:amy', '@amy.bsky.social')],
            [],
            [],
            true,
        ));

        $follows = $this->importCandidates($network)->forAccount($account)->follows;

        self::assertSame(
            [['@amy.bsky.social', 'new', null], ['@bob.bsky.social', 'subscribed', $bob->requireId()]],
            self::summary($follows),
        );
    }

    public function testAnOrdinaryFeedBeforeAProfileFeedDoesNotEndTheScan(): void
    {
        $account = $this->account();
        $this->subscribe('https://example.com/feed.xml');
        $bob = $this->subscribe('https://bsky.app/profile/did:plc:bob/rss');
        $network = new StubSocialCandidates(new NetworkCandidatesModel(
            [self::follow('did:plc:bob', '@bob.bsky.social')],
            [],
            [],
            false,
        ));

        $follows = $this->importCandidates($network)->forAccount($account)->follows;

        self::assertSame([['@bob.bsky.social', 'subscribed', $bob->requireId()]], self::summary($follows));
    }

    public function testListsAndCustomFeedsAreSortedByNameAndTruncationIsKept(): void
    {
        $network = new StubSocialCandidates(new NetworkCandidatesModel(
            [],
            [new SocialCollectionModel('at://list/b', 'writers'), new SocialCollectionModel('at://list/a', 'Artists')],
            [new SocialCollectionModel('at://feed/b', 'Feed 10'), new SocialCollectionModel('at://feed/a', 'Feed 9')],
            true,
        ));

        $candidates = $this->importCandidates($network)->forAccount($this->account());

        self::assertSame(
            ['Artists', 'writers'],
            array_map(static fn (SocialCollectionModel $list): string => $list->name, $candidates->lists),
        );
        self::assertSame(
            ['Feed 9', 'Feed 10'],
            array_map(static fn (SocialCollectionModel $feed): string => $feed->name, $candidates->customFeeds),
        );
        self::assertTrue($candidates->truncated);
    }

    public function testARefusedCredentialMarksTheAccountForReconnectingAndStillFails(): void
    {
        $account = $this->account();
        $network = new StubSocialCandidates(new SocialCredentialsRejectedException('Refused.'));

        try {
            $this->importCandidates($network)->forAccount($account);
            self::fail('The refusal must reach the caller.');
        } catch (SocialCredentialsRejectedException) {
            $this->entityManager->clear();
            $reloaded = $this->entityManager->find(SocialAccount::class, $account->requireId());
            self::assertSame(SocialAccountStatus::NeedsReconnect, $reloaded?->getStatus());
        }
    }

    public function testAnUnreachableNetworkLeavesTheAccountConnected(): void
    {
        $account = $this->account();
        $network = new StubSocialCandidates(new SocialNetworkUnreachableException('Down.'));

        try {
            $this->importCandidates($network)->forAccount($account);
            self::fail('The outage must reach the caller.');
        } catch (SocialNetworkUnreachableException) {
            $this->entityManager->clear();
            $reloaded = $this->entityManager->find(SocialAccount::class, $account->requireId());
            self::assertSame(SocialAccountStatus::Connected, $reloaded?->getStatus());
        }
    }
}
