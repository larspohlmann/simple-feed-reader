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

final class EntryStateRepositoryTest extends DbTestCase
{
    private User $user;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('state-repo@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->em->persist($this->feed);

        $this->em->persist(
            new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );
        $this->em->flush();
    }

    private function repo(): EntryStateRepository
    {
        $repo = self::getContainer()->get(EntryStateRepository::class);
        self::assertInstanceOf(EntryStateRepository::class, $repo);

        return $repo;
    }

    private function entry(string $guid): Entry
    {
        $entry = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    public function testEmptyEntryIdListReturnsEmptyWithoutQuerying(): void
    {
        self::assertSame([], $this->repo()->entryIdsWithStateForUser((int) $this->user->getId(), []));
    }

    public function testReturnsExactlyTheEntryIdsThatHaveAStateRow(): void
    {
        $withState = $this->entry('with-state');
        $withoutState = $this->entry('without-state');

        $state = new EntryState($this->user, $withState);
        $state->setIsHidden(true);
        $this->em->persist($state);
        $this->em->flush();

        $result = $this->repo()->entryIdsWithStateForUser(
            (int) $this->user->getId(),
            [(int) $withState->getId(), (int) $withoutState->getId()],
        );

        self::assertSame([(int) $withState->getId()], $result);
    }
}
