<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\FeedGroup;
use App\Enum\SocialNetwork;
use App\Repository\Exception\RecordNotFoundException;
use App\Repository\NetworkScope;
use App\Service\Refresh\Model\RefreshTarget\EveryFeedTargetModel;
use App\Service\Refresh\Model\RefreshTarget\FeedGroupTargetModel;
use App\Service\Refresh\Model\RefreshTarget\FeedTargetModel;
use App\Service\Refresh\Model\RefreshTarget\NetworkTargetModel;
use App\Service\Refresh\Model\RefreshTarget\NeverFetchedTargetModel;
use App\Service\Refresh\Model\RefreshTarget\TagTargetModel;
use App\Service\Refresh\UserRefreshScope;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserRefreshScopeTest extends DbTestCase
{
    private User $user;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->userFactory()->create('refresh-scope@example.com');
        $this->feed = new Feed('https://example.com/refresh-scope.xml');
        $this->entityManager->persist($this->feed);
        $this->entityManager->persist(
            new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );
        $this->entityManager->flush();
    }

    public function testNoTargetRefreshesEveryFeedOfTheUser(): void
    {
        $request = $this->scope()->requestFor($this->user->requireId(), null, null);

        self::assertSame($this->user->requireId(), $request->userId);
        self::assertEquals(new EveryFeedTargetModel(), $request->target);
        self::assertSame(25, $request->budgetSeconds);
    }

    public function testASubscribedFeedIsRefreshedAlone(): void
    {
        $request = $this->scope()->requestFor($this->user->requireId(), $this->feed->requireId(), null);

        self::assertSame($this->user->requireId(), $request->userId);
        self::assertEquals(new FeedTargetModel($this->feed->requireId()), $request->target);
        self::assertSame(25, $request->budgetSeconds);
    }

    public function testAFeedTheUserDoesNotSubscribeToIsNotFound(): void
    {
        $other = new Feed('https://example.com/not-subscribed.xml');
        $this->entityManager->persist($other);
        $this->entityManager->flush();

        $this->expectException(RecordNotFoundException::class);
        $this->expectExceptionMessage('No such subscription.');

        $this->scope()->requestFor($this->user->requireId(), $other->requireId(), null);
    }

    public function testAnOwnTagIsRefreshedAlone(): void
    {
        $tag = $this->tag($this->user);

        $request = $this->scope()->requestFor($this->user->requireId(), null, $tag->requireId());

        self::assertSame($this->user->requireId(), $request->userId);
        self::assertEquals(new TagTargetModel($tag->requireId()), $request->target);
        self::assertSame(25, $request->budgetSeconds);
    }

    public function testAnotherUsersTagIsNotFound(): void
    {
        $tag = $this->tag($this->userFactory()->create('refresh-scope-stranger@example.com'));

        $this->expectException(RecordNotFoundException::class);
        $this->expectExceptionMessage('No such tag.');

        $this->scope()->requestFor($this->user->requireId(), null, $tag->requireId());
    }

    public function testAFeedTakesPrecedenceOverATag(): void
    {
        $tag = $this->tag($this->user);

        $request = $this->scope()->requestFor($this->user->requireId(), $this->feed->requireId(), $tag->requireId());

        self::assertEquals(new FeedTargetModel($this->feed->requireId()), $request->target);
    }

    public function testANetworkRefreshIsAForcedRunOverThatNetworksFeeds(): void
    {
        $request = $this->scope()->requestForNetwork(
            $this->user->requireId(),
            NetworkScope::whole(SocialNetwork::Bluesky),
        );

        self::assertSame($this->user->requireId(), $request->userId);
        self::assertEquals(new NetworkTargetModel(NetworkScope::whole(SocialNetwork::Bluesky)), $request->target);
        self::assertTrue($request->force);
        self::assertSame(25, $request->budgetSeconds);
    }

    public function testAFeedGroupRefreshIsAForcedRunOverThatGroupsFeeds(): void
    {
        $request = $this->scope()->requestForFeedGroup($this->user->requireId(), FeedGroup::Feeds);

        self::assertSame($this->user->requireId(), $request->userId);
        self::assertEquals(new FeedGroupTargetModel(FeedGroup::Feeds), $request->target);
        self::assertTrue($request->force);
        self::assertSame(25, $request->budgetSeconds);
    }

    public function testANeverFetchedRefreshIsAForcedRunOverTheUsersUnfetchedFeeds(): void
    {
        $request = $this->scope()->requestForNeverFetched($this->user->requireId());

        self::assertSame($this->user->requireId(), $request->userId);
        self::assertEquals(new NeverFetchedTargetModel(), $request->target);
        self::assertTrue($request->force);
        self::assertSame(25, $request->budgetSeconds);
    }

    private function scope(): UserRefreshScope
    {
        $scope = self::getContainer()->get(UserRefreshScope::class);
        self::assertInstanceOf(UserRefreshScope::class, $scope);

        return $scope;
    }

    private function userFactory(): UserFactory
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return new UserFactory($this->entityManager, $hasher);
    }

    private function tag(User $owner): Tag
    {
        $tag = new Tag($owner, 'News');
        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        return $tag;
    }
}
