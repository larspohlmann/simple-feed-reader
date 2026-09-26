<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\Exception\RecordNotFoundException;
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
        $this->em->persist($this->feed);
        $this->em->persist(new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->em->flush();
    }

    public function testNoTargetRefreshesEveryFeedOfTheUser(): void
    {
        $request = $this->scope()->requestFor($this->user->requireId(), null, null);

        self::assertSame($this->user->requireId(), $request->userId);
        self::assertNull($request->feedId);
        self::assertNull($request->tagId);
        self::assertSame(25, $request->budgetSeconds);
    }

    public function testASubscribedFeedIsRefreshedAlone(): void
    {
        $request = $this->scope()->requestFor($this->user->requireId(), $this->feed->requireId(), null);

        self::assertSame($this->user->requireId(), $request->userId);
        self::assertSame($this->feed->requireId(), $request->feedId);
        self::assertNull($request->tagId);
        self::assertSame(25, $request->budgetSeconds);
    }

    public function testAFeedTheUserDoesNotSubscribeToIsNotFound(): void
    {
        $other = new Feed('https://example.com/not-subscribed.xml');
        $this->em->persist($other);
        $this->em->flush();

        $this->expectException(RecordNotFoundException::class);
        $this->expectExceptionMessage('No such subscription.');

        $this->scope()->requestFor($this->user->requireId(), $other->requireId(), null);
    }

    public function testAnOwnTagIsRefreshedAlone(): void
    {
        $tag = $this->tag($this->user);

        $request = $this->scope()->requestFor($this->user->requireId(), null, $tag->requireId());

        self::assertSame($this->user->requireId(), $request->userId);
        self::assertNull($request->feedId);
        self::assertSame($tag->requireId(), $request->tagId);
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

        self::assertSame($this->feed->requireId(), $request->feedId);
        self::assertNull($request->tagId);
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

        return new UserFactory($this->em, $hasher);
    }

    private function tag(User $owner): Tag
    {
        $tag = new Tag($owner, 'News');
        $this->em->persist($tag);
        $this->em->flush();

        return $tag;
    }
}
