<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryStateRepository;
use App\Tests\DbTestCase;

final class UnreadCountsTest extends DbTestCase
{
    private function repo(): EntryStateRepository
    {
        $repo = $this->entityManager->getRepository(EntryState::class);
        self::assertInstanceOf(EntryStateRepository::class, $repo);

        return $repo;
    }

    public function testCountsUnreadPerSubscriptionRespectingWatermarkAndState(): void
    {
        $user = new User('u@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($user);
        $feed = new Feed('https://example.com/f.xml');
        $this->entityManager->persist($feed);
        $sub = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $sub->setMarkedReadUntil(new \DateTimeImmutable('2026-07-10T00:00:00Z'));
        $this->entityManager->persist($sub);

        // under watermark → read; above → unread; explicit read; explicit unread.
        foreach ([['a', '2026-07-05'], ['b', '2026-07-20'], ['c', '2026-07-21'], ['d', '2026-07-22']] as [$g, $d]) {
            $publishedAt = new \DateTimeImmutable($d . 'T00:00:00Z');
            $e = new Entry($feed, $g, null, $g, new \DateTimeImmutable('2026-07-01T00:00:00Z'), $publishedAt);
            $e->setPublishedAt($publishedAt);
            $this->entityManager->persist($e);
            if ($g === 'c') {
                $st = new EntryState($user, $e);
                $st->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
                $this->entityManager->persist($st);
            }
        }
        $this->entityManager->flush();

        // Unread: b and d (a is under watermark, c is explicitly read).
        $counts = $this->repo()->unreadCountsForUser($user->requireId());
        self::assertSame(2, $counts[$sub->requireId()] ?? 0);
    }

    public function testSubscriptionWithNoUnreadIsAbsentFromMap(): void
    {
        $user = new User('empty@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($user);
        $feed = new Feed('https://example.com/empty.xml');
        $this->entityManager->persist($feed);
        $sub = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($sub);
        $this->entityManager->flush();

        $counts = $this->repo()->unreadCountsForUser($user->requireId());
        self::assertArrayNotHasKey($sub->requireId(), $counts);
    }

    public function testCrossFeedDuplicateCountsOnceAcrossSubscriptions(): void
    {
        $user = new User('dup@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($user);

        $feedA = new Feed('https://example.com/a.xml');
        $this->entityManager->persist($feedA);
        $feedB = new Feed('https://example.com/b.xml');
        $this->entityManager->persist($feedB);

        $subA = new Subscription($user, $feedA, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($subA);
        $subB = new Subscription($user, $feedB, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($subB);

        $publishedAt = new \DateTimeImmutable('2026-07-20T00:00:00Z');
        $entryA = new Entry(
            $feedA,
            'guid-a',
            'https://example.com/dup',
            'dup',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            $publishedAt,
            'shared-hash',
        );
        $entryA->setPublishedAt($publishedAt);
        $this->entityManager->persist($entryA);
        $this->entityManager->flush(); // entryA gets the lower id first.

        $entryB = new Entry(
            $feedB,
            'guid-b',
            'https://example.com/dup',
            'dup',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            $publishedAt,
            'shared-hash',
        );
        $entryB->setPublishedAt($publishedAt);
        $this->entityManager->persist($entryB);
        $this->entityManager->flush();

        $counts = $this->repo()->unreadCountsForUser($user->requireId());
        $subAId = $subA->requireId();
        $subBId = $subB->requireId();

        self::assertSame(1, ($counts[$subAId] ?? 0) + ($counts[$subBId] ?? 0));
        self::assertSame(1, $counts[$subAId] ?? 0);
    }

    public function testUnreadCopySurvivesWhenTheLowerIdDuplicateIsAlreadyRead(): void
    {
        $user = new User('read-lower@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($user);

        $feedA = new Feed('https://example.com/a.xml');
        $this->entityManager->persist($feedA);
        $feedB = new Feed('https://example.com/b.xml');
        $this->entityManager->persist($feedB);

        $subA = new Subscription($user, $feedA, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($subA);
        $subB = new Subscription($user, $feedB, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($subB);

        $publishedAt = new \DateTimeImmutable('2026-07-20T00:00:00Z');
        $lower = new Entry(
            $feedA,
            'guid-lower',
            'https://example.com/dup',
            'dup',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            $publishedAt,
            'shared-hash',
        );
        $this->entityManager->persist($lower);
        $this->entityManager->flush(); // $lower gets the lower id first.

        $higher = new Entry(
            $feedB,
            'guid-higher',
            'https://example.com/dup',
            'dup',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            $publishedAt,
            'shared-hash',
        );
        $this->entityManager->persist($higher);
        $state = new EntryState($user, $lower);
        $state->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
        $this->entityManager->persist($state);
        $this->entityManager->flush();

        // The collapse's inner scope must exclude the read lower copy, or it
        // wrongly suppresses the still-unread higher copy too.
        $counts = $this->repo()->unreadCountsForUser($user->requireId());

        self::assertSame(1, $counts[$subB->requireId()] ?? 0);
        self::assertSame(0, $counts[$subA->requireId()] ?? 0);
    }
}
