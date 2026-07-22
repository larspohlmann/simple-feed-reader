<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
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

        $old = new Entry($feed, 'old', null, 'Old', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $old->setPublishedAt(new \DateTimeImmutable('2026-07-05T00:00:00Z'));
        $new = new Entry($feed, 'new', null, 'New', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $new->setPublishedAt(new \DateTimeImmutable('2026-07-20T00:00:00Z'));
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
        $state->setIsRead(false);
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
        self::assertTrue($flipped->isRead());
        self::assertNotNull($flipped->getReadAt());
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
}
