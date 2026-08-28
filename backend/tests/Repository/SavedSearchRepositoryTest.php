<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\SavedSearch;
use App\Entity\User;
use App\Repository\SavedSearchRepository;
use App\Tests\DbTestCase;

final class SavedSearchRepositoryTest extends DbTestCase
{
    private function repo(): SavedSearchRepository
    {
        $repo = $this->em->getRepository(SavedSearch::class);
        self::assertInstanceOf(SavedSearchRepository::class, $repo);

        return $repo;
    }

    public function testFindForUserReturnsNewestFirstAndScopesToUser(): void
    {
        $owner = new User('owner@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $stranger = new User('stranger@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($owner);
        $this->em->persist($stranger);

        $first = new SavedSearch($owner, 'climate', false);
        $second = new SavedSearch($owner, 'rust lang', true);
        $strangers = new SavedSearch($stranger, 'not mine', false);
        $this->em->persist($first);
        $this->em->persist($second);
        $this->em->persist($strangers);
        $this->em->flush();

        $rows = $this->repo()->findForUser((int) $owner->getId());

        self::assertCount(2, $rows);
        self::assertSame('rust lang', $rows[0]->getTerm()); // newest first
        self::assertSame('climate', $rows[1]->getTerm());
    }

    public function testFindOneForUserByTermDistinguishesWholeWord(): void
    {
        $user = new User('u@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);
        $this->em->persist(new SavedSearch($user, 'punk', false));
        $this->em->persist(new SavedSearch($user, 'punk', true));
        $this->em->flush();

        $userId = (int) $user->getId();
        self::assertNotNull($this->repo()->findOneForUserByTerm($userId, 'punk', false, false));
        self::assertNotNull($this->repo()->findOneForUserByTerm($userId, 'punk', true, false));
        self::assertSame(true, $this->repo()->findOneForUserByTerm($userId, 'punk', true, false)->isWholeWord());
        self::assertNull($this->repo()->findOneForUserByTerm($userId, 'missing', false, false));
    }

    public function testFindOneForUserByTermDistinguishesPhrase(): void
    {
        // A phrase search and a plain substring search share a term but are two
        // distinct saved searches — the mode is part of a saved search's
        // identity, so the lookup must not confuse one for the other.
        $user = new User('phrase@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);
        $this->em->persist(new SavedSearch($user, 'climate change', false, false));
        $this->em->persist(new SavedSearch($user, 'climate change', false, true));
        $this->em->flush();

        $userId = (int) $user->getId();
        $substring = $this->repo()->findOneForUserByTerm($userId, 'climate change', false, false);
        $phrase = $this->repo()->findOneForUserByTerm($userId, 'climate change', false, true);
        self::assertNotNull($substring);
        self::assertNotNull($phrase);
        self::assertFalse($substring->isPhrase());
        self::assertTrue($phrase->isPhrase());
    }

    public function testFindOneOwnedByRejectsAnotherUser(): void
    {
        $owner = new User('owner2@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $stranger = new User('stranger2@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($owner);
        $this->em->persist($stranger);
        $saved = new SavedSearch($owner, 'mine', false);
        $this->em->persist($saved);
        $this->em->flush();

        self::assertNotNull($this->repo()->findOneOwnedBy((int) $saved->getId(), (int) $owner->getId()));
        self::assertNull($this->repo()->findOneOwnedBy((int) $saved->getId(), (int) $stranger->getId()));
    }
}
