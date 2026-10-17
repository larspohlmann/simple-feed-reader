<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SourceFormat;
use App\Repository\RefusedProfileSubscriptionRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RefusedProfileSubscriptionRepositoryTest extends DbTestCase
{
    private const string NOW = '2026-10-17 12:00:00';
    private const string PROFILE = 'https://bsky.app/profile/did:plc:rusty/rss';

    public function testASubscriberWithAnAccountOnAProfileFeedRefusedWith403IsACandidate(): void
    {
        $user = $this->user('refused-candidate@example.test');
        $this->connect($user);
        $subscription = $this->subscribe($user, self::PROFILE, 'HTTP 403');

        self::assertSame([$subscription], $this->subscriptions()->findOnRefusedBlueskyProfileFeeds(null, 10));
    }

    #[DataProvider('otherFeeds')]
    public function testNoOtherFeedIsACandidate(string $feedUrl, ?string $failure, string $sourceFormat): void
    {
        $user = $this->user('refused-other-feed@example.test');
        $this->connect($user);
        $this->subscribe($user, $feedUrl, $failure, $sourceFormat);

        self::assertSame([], $this->subscriptions()->findOnRefusedBlueskyProfileFeeds(null, 10));
    }

    /** @return iterable<string, array{string, ?string, string}> */
    public static function otherFeeds(): iterable
    {
        yield 'another failure' => [self::PROFILE, 'HTTP 500', SourceFormat::XML];
        yield 'a healthy feed' => [self::PROFILE, null, SourceFormat::XML];
        yield 'a private feed' => ['social:bluesky:1:author:did:plc:rusty', 'HTTP 403', SourceFormat::BLUESKY_API];
        yield 'a mastodon profile' => ['https://mastodon.social/@rusty.rss', 'HTTP 403', SourceFormat::XML];
    }

    public function testASubscriberWithoutAnAccountIsNoCandidate(): void
    {
        $this->subscribe($this->user('refused-no-account@example.test'), self::PROFILE, 'HTTP 403');

        self::assertSame([], $this->subscriptions()->findOnRefusedBlueskyProfileFeeds(null, 10));
    }

    public function testASubscriberWhoseAccountNeedsAReconnectIsNoCandidate(): void
    {
        $user = $this->user('refused-reconnect@example.test');
        $this->connect($user)->markNeedsReconnect();
        $this->entityManager->flush();
        $this->subscribe($user, self::PROFILE, 'HTTP 403');

        self::assertSame([], $this->subscriptions()->findOnRefusedBlueskyProfileFeeds(null, 10));
    }

    public function testTheLimitHolds(): void
    {
        $user = $this->user('refused-limit@example.test');
        $this->connect($user);
        $this->subscribe($user, 'https://bsky.app/profile/did:plc:one/rss', 'HTTP 403');
        $this->subscribe($user, 'https://bsky.app/profile/did:plc:two/rss', 'HTTP 403');

        self::assertCount(1, $this->subscriptions()->findOnRefusedBlueskyProfileFeeds(null, 1));
    }

    public function testAPageStartsAfterTheCursorInFeedThenSubscriptionOrder(): void
    {
        $first = $this->user('refused-cursor-first@example.test');
        $second = $this->user('refused-cursor-second@example.test');
        $this->connect($first);
        $this->connect($second);
        $one = $this->subscribe($first, 'https://bsky.app/profile/did:plc:one/rss', 'HTTP 403');
        $two = $this->subscribe($first, 'https://bsky.app/profile/did:plc:two/rss', 'HTTP 403');
        $oneAgain = new Subscription($second, $one->getFeed(), new \DateTimeImmutable(self::NOW));
        $this->entityManager->persist($oneAgain);
        $this->entityManager->flush();

        self::assertSame([$oneAgain, $two], $this->subscriptions()->findOnRefusedBlueskyProfileFeeds($one, 10));
        self::assertSame([$two], $this->subscriptions()->findOnRefusedBlueskyProfileFeeds($oneAgain, 10));
        self::assertSame([], $this->subscriptions()->findOnRefusedBlueskyProfileFeeds($two, 10));
    }

    private function subscribe(
        User $user,
        string $feedUrl,
        ?string $failure,
        string $sourceFormat = SourceFormat::XML,
    ): Subscription {
        $now = new \DateTimeImmutable(self::NOW);
        $feed = new Feed($feedUrl);
        $feed->setSourceFormat($sourceFormat);
        if (null !== $failure) {
            $feed->recordFailedFetch($now, $feedUrl . ': ' . $failure, 60);
        }
        $this->entityManager->persist($feed);
        $subscription = new Subscription($user, $feed, $now);
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $subscription;
    }

    private function connect(User $user): SocialAccount
    {
        $account = SocialAccounts::blueskyOf($user);
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    private function user(string $email): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return (new UserFactory($this->entityManager, $hasher))->create($email);
    }

    private function subscriptions(): RefusedProfileSubscriptionRepository
    {
        $subscriptions = self::getContainer()->get(RefusedProfileSubscriptionRepository::class);
        self::assertInstanceOf(RefusedProfileSubscriptionRepository::class, $subscriptions);

        return $subscriptions;
    }
}
