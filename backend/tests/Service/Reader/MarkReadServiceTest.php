<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Exception\ValidationException;
use App\Service\Reader\MarkReadService;
use App\Tests\DbTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class MarkReadServiceTest extends DbTestCase
{
    private function service(): MarkReadService
    {
        $svc = self::getContainer()->get(MarkReadService::class);
        self::assertInstanceOf(MarkReadService::class, $svc);

        return $svc;
    }

    /** @return array{User, Subscription, Entry, Entry} */
    private function seed(): array
    {
        $user = new User('m@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);
        $feed = new Feed('https://example.com/f.xml');
        $this->em->persist($feed);
        $sub = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($sub);

        $oldPublishedAt = new \DateTimeImmutable('2026-07-05T00:00:00Z');
        $old = new Entry($feed, 'old', null, 'Old', new \DateTimeImmutable('2026-07-01T00:00:00Z'), $oldPublishedAt);
        $old->setPublishedAt($oldPublishedAt);
        $newPublishedAt = new \DateTimeImmutable('2026-07-20T00:00:00Z');
        $new = new Entry($feed, 'new', null, 'New', new \DateTimeImmutable('2026-07-01T00:00:00Z'), $newPublishedAt);
        $new->setPublishedAt($newPublishedAt);
        $this->em->persist($old);
        $this->em->persist($new);
        $this->em->flush();

        return [$user, $sub, $old, $new];
    }

    public function testAllScopeSetsWatermarkAndFlipsExplicitUnread(): void
    {
        [$user, $sub, $old] = $this->seed();
        // A pre-existing explicit "unread" below the mark point.
        $state = new EntryState($user, $old);
        $state->setIsHidden(false);
        $this->em->persist($state);
        $this->em->flush();

        $this->service()->mark($user, 'all', null, new \DateTimeImmutable('2026-07-10T00:00:00Z'));
        $this->em->clear();

        $reloaded = $this->em->getRepository(Subscription::class)->find($sub->getId());
        self::assertNotNull($reloaded);
        self::assertSame(
            '2026-07-10T00:00:00+00:00',
            $reloaded->getMarkedReadUntil()?->format(\DateTimeInterface::ATOM),
        );

        $flipped = $this->em->getRepository(EntryState::class)
            ->findOneForUserEntry((int) $user->getId(), (int) $old->getId());
        self::assertNotNull($flipped);
        self::assertTrue($flipped->isHidden());
        self::assertNotNull($flipped->getHiddenAt());
    }

    public function testWatermarkOnlyAdvances(): void
    {
        [$user, $sub] = $this->seed();
        $sub->setMarkedReadUntil(new \DateTimeImmutable('2026-07-15T00:00:00Z'));
        $this->em->flush();

        $this->service()->mark($user, 'all', null, new \DateTimeImmutable('2026-07-10T00:00:00Z'));
        $this->em->clear();

        $reloaded = $this->em->getRepository(Subscription::class)->find($sub->getId());
        self::assertNotNull($reloaded);
        self::assertSame(
            '2026-07-15T00:00:00+00:00',
            $reloaded->getMarkedReadUntil()?->format(\DateTimeInterface::ATOM),
        );
    }

    public function testFeedScopeRequiresOwnership(): void
    {
        [$user] = $this->seed();
        $this->expectException(NotFoundHttpException::class);
        $this->service()->mark($user, 'feed', 999999, new \DateTimeImmutable('2026-07-10T00:00:00Z'));
    }

    public function testTagScope(): void
    {
        [$user, $sub] = $this->seed();
        $tag = new Tag($user, 'news');
        $this->em->persist($tag);
        $sub->addTag($tag);
        $this->em->flush();

        $this->service()->mark($user, 'tag', (int) $tag->getId(), new \DateTimeImmutable('2026-07-25T00:00:00Z'));
        $this->em->clear();

        $reloaded = $this->em->getRepository(Subscription::class)->find($sub->getId());
        self::assertNotNull($reloaded);
        self::assertSame(
            '2026-07-25T00:00:00+00:00',
            $reloaded->getMarkedReadUntil()?->format(\DateTimeInterface::ATOM),
        );
    }

    public function testFlippingAFavoritedRowKeepsItFavoriteAndKept(): void
    {
        // Requirement: mark-read flips isHidden on an existing row but must never
        // disturb the favorite/kept flags that protect an entry from pruning.
        [$user, , $old] = $this->seed();
        $state = new EntryState($user, $old);
        $state->setIsHidden(false);
        $state->setIsFavorite(true);
        $state->setIsKept(true);
        $this->em->persist($state);
        $this->em->flush();

        $this->service()->mark($user, 'all', null, new \DateTimeImmutable('2026-07-10T00:00:00Z'));
        $this->em->clear();

        $reloaded = $this->em->getRepository(EntryState::class)
            ->findOneForUserEntry((int) $user->getId(), (int) $old->getId());
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isHidden());
        self::assertTrue($reloaded->isFavorite(), 'favorite must survive mark-read');
        self::assertTrue($reloaded->isKept(), 'kept must survive mark-read');
    }

    public function testMarkAllReadDoesNotChangeViewed(): void
    {
        [$user, , $old] = $this->seed();
        $viewedAt = new \DateTimeImmutable('2026-07-05T12:00:00Z');
        $state = new EntryState($user, $old);
        $state->markViewed($viewedAt);
        $this->em->persist($state);
        $this->em->flush();

        $this->service()->mark($user, 'all', null, new \DateTimeImmutable('2026-07-10T00:00:00Z'));
        $this->em->clear();

        $reloaded = $this->em->getRepository(EntryState::class)
            ->findOneForUserEntry((int) $user->getId(), (int) $old->getId());
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isHidden());
        self::assertTrue($reloaded->isViewed());
        self::assertSame(
            $viewedAt->format(\DateTimeInterface::ATOM),
            $reloaded->getViewedAt()?->format(\DateTimeInterface::ATOM),
        );
    }

    public function testTagScopeRejectsAnotherUsersTag(): void
    {
        [$user] = $this->seed();
        $stranger = new User('stranger@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($stranger);
        $strangerTag = new Tag($stranger, 'secret');
        $this->em->persist($strangerTag);
        $this->em->flush();

        $this->expectException(NotFoundHttpException::class);
        $this->service()->mark(
            $user,
            'tag',
            (int) $strangerTag->getId(),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
    }

    public function testFeedScopeWithoutIdIsRejected(): void
    {
        [$user] = $this->seed();
        $this->expectException(ValidationException::class);
        $this->service()->mark($user, 'feed', null, new \DateTimeImmutable('2026-07-10T00:00:00Z'));
    }

    /**
     * @return array{User, Subscription, Entry}
     */
    private function seedExcludedFeedSubscription(User $user): array
    {
        $feed = new Feed('https://example.com/excluded.xml');
        $this->em->persist($feed);
        $sub = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $sub->setIncludeInAllItems(false);
        $this->em->persist($sub);

        $publishedAt = new \DateTimeImmutable('2026-07-05T00:00:00Z');
        $entry = new Entry(
            $feed,
            'excluded',
            null,
            'Excluded',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            $publishedAt,
        );
        $entry->setPublishedAt($publishedAt);
        $this->em->persist($entry);

        $state = new EntryState($user, $entry);
        $state->setIsHidden(false);
        $this->em->persist($state);
        $this->em->flush();

        return [$user, $sub, $entry];
    }

    public function testAllScopeSkipsFeedExcludedFromAllItems(): void
    {
        [$user, $sub, $old] = $this->seed();
        // A pre-existing explicit "unread" on the included feed, below the mark point.
        $includedState = new EntryState($user, $old);
        $includedState->setIsHidden(false);
        $this->em->persist($includedState);
        $this->em->flush();

        [, $excludedSub, $excludedEntry] = $this->seedExcludedFeedSubscription($user);

        $this->service()->mark($user, 'all', null, new \DateTimeImmutable('2026-07-10T00:00:00Z'));
        $this->em->clear();

        $reloadedIncludedSub = $this->em->getRepository(Subscription::class)->find($sub->getId());
        self::assertNotNull($reloadedIncludedSub);
        self::assertSame(
            '2026-07-10T00:00:00+00:00',
            $reloadedIncludedSub->getMarkedReadUntil()?->format(\DateTimeInterface::ATOM),
        );

        $reloadedExcludedSub = $this->em->getRepository(Subscription::class)->find($excludedSub->getId());
        self::assertNotNull($reloadedExcludedSub);
        self::assertNull(
            $reloadedExcludedSub->getMarkedReadUntil(),
            'excluded feed must not have its watermark advanced by scope "all"',
        );

        $flippedIncluded = $this->em->getRepository(EntryState::class)
            ->findOneForUserEntry((int) $user->getId(), (int) $old->getId());
        self::assertNotNull($flippedIncluded);
        self::assertTrue($flippedIncluded->isHidden(), 'included feed entry must be marked read');

        $untouchedExcluded = $this->em->getRepository(EntryState::class)
            ->findOneForUserEntry((int) $user->getId(), (int) $excludedEntry->getId());
        self::assertNotNull($untouchedExcluded);
        self::assertFalse($untouchedExcluded->isHidden(), 'excluded feed entry must stay unread');
    }

    public function testFeedScopeStillMarksAFeedExcludedFromAllItems(): void
    {
        [$user] = $this->seed();
        [, $excludedSub, $excludedEntry] = $this->seedExcludedFeedSubscription($user);

        $this->service()->mark(
            $user,
            'feed',
            (int) $excludedSub->getId(),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
        $this->em->clear();

        $reloadedSub = $this->em->getRepository(Subscription::class)->find($excludedSub->getId());
        self::assertNotNull($reloadedSub);
        self::assertSame(
            '2026-07-10T00:00:00+00:00',
            $reloadedSub->getMarkedReadUntil()?->format(\DateTimeInterface::ATOM),
        );

        $flipped = $this->em->getRepository(EntryState::class)
            ->findOneForUserEntry((int) $user->getId(), (int) $excludedEntry->getId());
        self::assertNotNull($flipped);
        self::assertTrue($flipped->isHidden(), 'scope "feed" must still mark an excluded feed');
    }

    public function testTagScopeStillMarksAFeedExcludedFromAllItems(): void
    {
        [$user] = $this->seed();
        [, $excludedSub, $excludedEntry] = $this->seedExcludedFeedSubscription($user);
        $tag = new Tag($user, 'excluded-tag');
        $this->em->persist($tag);
        $excludedSub->addTag($tag);
        $this->em->flush();

        $this->service()->mark($user, 'tag', (int) $tag->getId(), new \DateTimeImmutable('2026-07-10T00:00:00Z'));
        $this->em->clear();

        $reloadedSub = $this->em->getRepository(Subscription::class)->find($excludedSub->getId());
        self::assertNotNull($reloadedSub);
        self::assertSame(
            '2026-07-10T00:00:00+00:00',
            $reloadedSub->getMarkedReadUntil()?->format(\DateTimeInterface::ATOM),
        );

        $flipped = $this->em->getRepository(EntryState::class)
            ->findOneForUserEntry((int) $user->getId(), (int) $excludedEntry->getId());
        self::assertNotNull($flipped);
        self::assertTrue($flipped->isHidden(), 'scope "tag" must still mark an excluded feed');
    }
}
