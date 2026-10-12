<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Feed;
use App\Entity\SealedSecret;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\SocialAccountRepository;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\PrivateFeeds;
use App\Service\Social\SocialAccountEditor;
use App\Tests\DbTestCase;
use App\Tests\Support\PrivateFeedsWiring;
use App\Tests\Support\ProfileFeedSubscriptions;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SocialAccountEditorTest extends DbTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('social-editor@example.test');
    }

    public function testMakeDefaultMovesTheFlagWithinTheNetworkOnly(): void
    {
        $first = $this->stored(SocialNetwork::Bluesky, 'did:plc:one', true);
        $second = $this->stored(SocialNetwork::Bluesky, 'did:plc:two', false);
        $mastodon = $this->stored(SocialNetwork::Mastodon, 'alice@mastodon.example', true);

        $this->editor()->makeDefault($second);
        $this->entityManager->clear();

        self::assertSame(
            [false, true, true],
            [$this->storedDefault($first), $this->storedDefault($second), $this->storedDefault($mastodon)],
        );
    }

    public function testDisconnectingTheDefaultPromotesTheOldestRemaining(): void
    {
        $first = $this->stored(SocialNetwork::Bluesky, 'did:plc:one', true);
        $second = $this->stored(SocialNetwork::Bluesky, 'did:plc:two', false);
        $third = $this->stored(SocialNetwork::Bluesky, 'did:plc:three', false);

        $firstId = $first->requireId();

        $this->editor()->disconnect($first);
        $this->entityManager->clear();

        self::assertNull($this->repository()->find($firstId));
        self::assertSame([true, false], [$this->storedDefault($second), $this->storedDefault($third)]);
    }

    public function testDisconnectingANonDefaultLeavesTheDefault(): void
    {
        $first = $this->stored(SocialNetwork::Bluesky, 'did:plc:one', true);
        $second = $this->stored(SocialNetwork::Bluesky, 'did:plc:two', false);

        $this->editor()->disconnect($second);

        self::assertTrue($first->isDefault());
        self::assertSame([$first], $this->editor()->accountsOf($this->user));
    }

    public function testDisconnectingTheLastAccountOnANetworkLeavesNoDefault(): void
    {
        $only = $this->stored(SocialNetwork::Bluesky, 'did:plc:one', true);

        $this->editor()->disconnect($only);

        self::assertSame([], $this->repository()->findAllOnNetwork($this->user, SocialNetwork::Bluesky));
    }

    public function testDisconnectingRemovesThePrivateFeedsAndKeepsTheImportedFollows(): void
    {
        $account = $this->stored(SocialNetwork::Bluesky, 'did:plc:one', true);
        $this->privateFeeds()->create($account, PrivateFeedModel::home());
        $follow = (new ProfileFeedSubscriptions($this->entityManager))
            ->subscribe($this->user, 'https://bsky.app/profile/did:plc:bob/rss');
        $homeUrl = sprintf('social:bluesky:%d:home', $account->requireId());

        $this->editor()->disconnect($account);
        $this->entityManager->clear();

        self::assertNull($this->entityManager->getRepository(Feed::class)->findOneBy(['url' => $homeUrl]));
        self::assertSame(0, $this->entityManager->getRepository(SocialFeed::class)->count([]));
        self::assertNotNull($this->entityManager->find(Subscription::class, $follow->requireId()));
    }

    public function testDisconnectingLeavesAnotherUsersSubscriptionToTheSameFeed(): void
    {
        $account = $this->stored(SocialNetwork::Bluesky, 'did:plc:one', true);
        $this->privateFeeds()->create($account, PrivateFeedModel::home());
        $home = $this->entityManager->getRepository(SocialFeed::class)->findOneBy(['socialAccount' => $account]);
        self::assertNotNull($home);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stranger = (new UserFactory($this->entityManager, $hasher))->create('stranger@example.test');
        $theirs = new Subscription($stranger, $home->getFeed(), new \DateTimeImmutable('2026-10-12 10:00:00'));
        $this->entityManager->persist($theirs);
        $this->entityManager->flush();
        $theirsId = $theirs->requireId();

        $this->editor()->disconnect($account);
        $this->entityManager->clear();

        self::assertNotNull($this->entityManager->find(Subscription::class, $theirsId));
    }

    private function stored(SocialNetwork $network, string $identity, bool $default): SocialAccount
    {
        $account = new SocialAccount(
            $this->user,
            $network,
            $identity,
            '@' . $identity,
            'https://service.example.test',
            new SealedSecret('c', 'n', 's', 1),
            'mnop',
            new \DateTimeImmutable('2026-10-12 10:00:00'),
        );
        if ($default) {
            $account->becomeDefault();
        }
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    private function storedDefault(SocialAccount $account): ?bool
    {
        return $this->repository()->find($account->requireId())?->isDefault();
    }

    private function editor(): SocialAccountEditor
    {
        return new SocialAccountEditor($this->repository(), $this->privateFeeds(), $this->entityManager);
    }

    private function privateFeeds(): PrivateFeeds
    {
        return PrivateFeedsWiring::build(
            self::getContainer(),
            $this->entityManager,
            new MockClock('2026-10-12 12:00:00', 'UTC'),
        );
    }

    private function repository(): SocialAccountRepository
    {
        /** @var SocialAccountRepository $repository */
        $repository = $this->entityManager->getRepository(SocialAccount::class);

        return $repository;
    }
}
