<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\SavedSearchEntry;
use App\Entity\User;
use App\Repository\SavedSearchEntryRepository;
use App\Tests\DbTestCase;

final class SavedSearchMembershipLoaderTest extends DbTestCase
{
    public function testReturnsEveryOwnedSearchPerEntryNewestFirst(): void
    {
        $user = $this->user('member@example.com');
        $entry = $this->entry($this->feed());
        $older = $this->savedSearchWithMember($user, 'climate', $entry);
        $newer = $this->savedSearchWithMember($user, 'rocket', $entry);

        $byEntry = $this->repository()->savedSearchesByEntry([(int) $entry->getId()], (int) $user->getId());

        $pills = $byEntry[(int) $entry->getId()];
        self::assertSame([(int) $newer->getId(), (int) $older->getId()], array_column($pills, 'id'));
        self::assertSame($newer->getSlug(), $pills[0]['slug']);
        self::assertSame($newer->getTerm(), $pills[0]['term']);
    }

    public function testExcludesAnotherUsersSearch(): void
    {
        $owner = $this->user('owner@example.com');
        $stranger = $this->user('stranger@example.com');
        $entry = $this->entry($this->feed());
        $this->savedSearchWithMember($stranger, 'climate', $entry);

        $byEntry = $this->repository()->savedSearchesByEntry([(int) $entry->getId()], (int) $owner->getId());

        self::assertSame([], $byEntry);
    }

    private function repository(): SavedSearchEntryRepository
    {
        $repository = self::getContainer()->get(SavedSearchEntryRepository::class);
        self::assertInstanceOf(SavedSearchEntryRepository::class, $repository);

        return $repository;
    }

    private function user(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function feed(): Feed
    {
        $feed = new Feed('https://example.com/membership-loader-feed.xml');
        $this->em->persist($feed);
        $this->em->flush();

        return $feed;
    }

    private function entry(Feed $feed): Entry
    {
        $entry = new Entry(
            $feed,
            'membership-loader-guid',
            'https://example.com/membership-loader-entry',
            'Climate report',
            new \DateTimeImmutable('2026-07-02T00:00:00Z'),
            new \DateTimeImmutable('2026-07-02T00:00:00Z'),
        );
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function savedSearchWithMember(User $user, string $term, Entry $entry): SavedSearch
    {
        $search = new SavedSearch($user, $term, false);
        $this->em->persist($search);
        $this->em->flush();
        $search->setSlug($search->getId() . '-' . $term);
        $this->em->persist(new SavedSearchEntry($search, $entry, new \DateTimeImmutable('2026-09-22T10:00:00')));
        $this->em->flush();

        return $search;
    }
}
