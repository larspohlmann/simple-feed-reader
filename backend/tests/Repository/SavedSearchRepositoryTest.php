<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\SavedSearch;
use App\Entity\User;
use App\Repository\Exception\RecordNotFoundException;
use App\Repository\SavedSearchRepository;
use App\Tests\DbTestCase;

final class SavedSearchRepositoryTest extends DbTestCase
{
    private function repository(): SavedSearchRepository
    {
        $repository = $this->entityManager->getRepository(SavedSearch::class);
        self::assertInstanceOf(SavedSearchRepository::class, $repository);

        return $repository;
    }

    public function testFindForUserReturnsNewestFirstAndScopesToUser(): void
    {
        $owner = new User('owner@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $stranger = new User('stranger@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($owner);
        $this->entityManager->persist($stranger);

        $first = new SavedSearch($owner, 'climate', false);
        $second = new SavedSearch($owner, 'rust lang', true);
        $strangers = new SavedSearch($stranger, 'not mine', false);
        $this->entityManager->persist($first);
        $this->entityManager->persist($second);
        $this->entityManager->persist($strangers);
        $this->entityManager->flush();

        $rows = $this->repository()->findForUser($owner->requireId());

        self::assertCount(2, $rows);
        self::assertSame('rust lang', $rows[0]->getTerm()); // newest first
        self::assertSame('climate', $rows[1]->getTerm());
    }

    public function testIdsForUserAnswersNewestFirstAndScopesToUser(): void
    {
        $owner = new User('ids-owner@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $stranger = new User('ids-stranger@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($owner);
        $this->entityManager->persist($stranger);
        $first = new SavedSearch($owner, 'climate', false);
        $second = new SavedSearch($owner, 'rocket', false);
        $this->entityManager->persist($first);
        $this->entityManager->persist($second);
        $this->entityManager->persist(new SavedSearch($stranger, 'not mine', false));
        $this->entityManager->flush();

        self::assertSame(
            [$second->getId(), $first->getId()],
            $this->repository()->idsForUser($owner->requireId()),
        );
    }

    public function testFindOneForUserByTermDistinguishesWholeWord(): void
    {
        $user = new User('u@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($user);
        $this->entityManager->persist(new SavedSearch($user, 'punk', false));
        $this->entityManager->persist(new SavedSearch($user, 'punk', true));
        $this->entityManager->flush();

        $userId = $user->requireId();
        self::assertNotNull($this->repository()->findOneForUserByTerm($userId, 'punk', false, false));
        self::assertNotNull($this->repository()->findOneForUserByTerm($userId, 'punk', true, false));
        self::assertSame(true, $this->repository()->findOneForUserByTerm($userId, 'punk', true, false)->isWholeWord());
        self::assertNull($this->repository()->findOneForUserByTerm($userId, 'missing', false, false));
    }

    public function testFindOneForUserByTermDistinguishesPhrase(): void
    {
        // A phrase search and a plain substring search share a term but are two
        // distinct saved searches — the mode is part of a saved search's
        // identity, so the lookup must not confuse one for the other.
        $user = new User('phrase@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($user);
        $this->entityManager->persist(new SavedSearch($user, 'climate change', false, false));
        $this->entityManager->persist(new SavedSearch($user, 'climate change', false, true));
        $this->entityManager->flush();

        $userId = $user->requireId();
        $substring = $this->repository()->findOneForUserByTerm($userId, 'climate change', false, false);
        $phrase = $this->repository()->findOneForUserByTerm($userId, 'climate change', false, true);
        self::assertNotNull($substring);
        self::assertNotNull($phrase);
        self::assertFalse($substring->isPhrase());
        self::assertTrue($phrase->isPhrase());
    }

    public function testGetOneForUserReturnsTheOwnersSavedSearch(): void
    {
        $owner = new User('owner3@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($owner);
        $saved = new SavedSearch($owner, 'mine', false);
        $this->entityManager->persist($saved);
        $this->entityManager->flush();

        self::assertSame($saved, $this->repository()->getOneForUser($owner->requireId(), $saved->requireId()));
    }

    public function testGetOneForUserRefusesAnotherUsersSavedSearch(): void
    {
        $owner = new User('owner4@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $stranger = new User('stranger4@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($owner);
        $this->entityManager->persist($stranger);
        $saved = new SavedSearch($owner, 'mine', false);
        $this->entityManager->persist($saved);
        $this->entityManager->flush();

        $this->expectException(RecordNotFoundException::class);
        $this->expectExceptionMessage('No such saved search.');

        $this->repository()->getOneForUser($stranger->requireId(), $saved->requireId());
    }
}
