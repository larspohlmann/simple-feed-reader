<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\User;
use App\Entity\YourStreamWord;
use App\Enum\FeedLanguage;
use App\Repository\YourStreamWordRepository;
use App\Tests\DbTestCase;

final class YourStreamWordRepositoryTest extends DbTestCase
{
    public function testFindStrengthsForUsersCombinesWeightAndRarityPerLanguageKey(): void
    {
        $user = $this->account('strength@example.com');
        $bare = $this->account('bare@example.com');
        $this->entityManager->persist(new YourStreamWord($user, FeedLanguage::English, 'solar', 'Solar', 0.8, 2.5));
        $this->entityManager->persist(new YourStreamWord($user, null, 'solar', 'Solar', 0.6, 1.0));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $models = $this->words()->findStrengthsForUsers([$user->requireId(), $bare->requireId()]);

        $strengths = $models[$user->requireId()];
        self::assertEqualsWithDelta(2.0, $strengths->readStrengths(FeedLanguage::English)['solar'], 1e-9);
        self::assertEqualsWithDelta(0.6, $strengths->readStrengths(null)['solar'], 1e-9);
        self::assertNull($strengths->readStrengths(FeedLanguage::German)['solar'] ?? null);
        self::assertNull($models[$bare->requireId()]->readStrengths(FeedLanguage::English)['solar'] ?? null);
    }

    public function testFindStrengthsForNoUsersIsEmpty(): void
    {
        self::assertSame([], $this->words()->findStrengthsForUsers([]));
    }

    public function testFindForUserOrdersByWeightThenStem(): void
    {
        $user = $this->account('ordered@example.com');
        foreach ([['wind', 0.5], ['solar', 1.0], ['atom', 0.5]] as [$stem, $weight]) {
            $this->entityManager->persist(new YourStreamWord($user, FeedLanguage::English, $stem, $stem, $weight, 1.0));
        }
        $this->entityManager->flush();

        self::assertSame(['solar', 'atom', 'wind'], array_map(
            static fn (YourStreamWord $word): string => $word->getStem(),
            $this->words()->findForUser($user->requireId()),
        ));
    }

    public function testHasWordsForUserHoldsOnlyForAnAccountWithWords(): void
    {
        $user = $this->account('has-words@example.com');
        $bare = $this->account('no-words@example.com');
        $this->entityManager->persist(new YourStreamWord($user, null, 'solar', 'solar', 1.0, 1.0));
        $this->entityManager->flush();

        self::assertTrue($this->words()->hasWordsForUser($user->requireId()));
        self::assertFalse($this->words()->hasWordsForUser($bare->requireId()));
    }

    public function testDeleteForUserRemovesOnlyThatAccountsWordsAndDetachesThem(): void
    {
        $user = $this->account('deleted@example.com');
        $other = $this->account('kept@example.com');
        $managed = new YourStreamWord($user, null, 'solar', 'solar', 1.0, 1.0);
        $this->entityManager->persist($managed);
        $this->entityManager->persist(new YourStreamWord($other, null, 'wind', 'wind', 1.0, 1.0));
        $this->entityManager->flush();

        $this->words()->deleteForUser($user->requireId());

        self::assertFalse($this->entityManager->contains($managed));
        self::assertSame([], $this->words()->findForUser($user->requireId()));
        self::assertCount(1, $this->words()->findForUser($other->requireId()));
    }

    private function account(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function words(): YourStreamWordRepository
    {
        $words = self::getContainer()->get(YourStreamWordRepository::class);
        self::assertInstanceOf(YourStreamWordRepository::class, $words);

        return $words;
    }
}
