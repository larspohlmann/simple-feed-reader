<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\InterestTerm;
use App\Entity\User;
use App\Enum\FeedLanguage;
use App\Repository\InterestTermRepository;
use App\Tests\DbTestCase;

final class InterestTermRepositoryTest extends DbTestCase
{
    public function testFindTermsForUsersCombinesWeightAndRarityPerLanguageKey(): void
    {
        $user = new User('strength@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $bare = new User('bare@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($user);
        $this->entityManager->persist($bare);
        $english = new InterestTerm($user, 'solar', FeedLanguage::English, 0.8);
        $english->recordRarity(2.5);
        $this->entityManager->persist($english);
        $this->entityManager->persist(new InterestTerm($user, 'solar', null, 0.6));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $repository = self::getContainer()->get(InterestTermRepository::class);
        $models = $repository->findTermsForUsers([$user->requireId(), $bare->requireId()]);

        $terms = $models[$user->requireId()];
        self::assertEqualsWithDelta(2.0, $terms->findStrength(FeedLanguage::English, 'solar'), 1e-9);
        self::assertEqualsWithDelta(0.6, $terms->findStrength(null, 'solar'), 1e-9);
        self::assertNull($terms->findStrength(FeedLanguage::German, 'solar'));
        self::assertTrue($models[$bare->requireId()]->isEmpty());
        self::assertFalse($terms->isEmpty());
    }
}
