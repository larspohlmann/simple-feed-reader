<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\ForYouStreamTuning;
use App\Entity\InterestTermSet;
use App\Entity\RecommendationSettings;
use App\Entity\User;
use App\Enum\ForYouStreamPrecision;
use App\Repository\InterestTermSetRepository;
use App\Service\Curation\Ai\Crypto\ApiKeyCipher;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;

final class InterestTermSetRepositoryTest extends DbTestCase
{
    private const int CEILING = 10;
    private const string NOW = '2026-11-27 10:00:00';

    public function testFindBelowMarkSkipsADisabledAccountAndKeepsOneWithoutSettings(): void
    {
        $unset = $this->accountAtMark('unset@example.com', 3);
        $enabled = $this->accountAtMark('enabled@example.com', 5);
        $this->storeTuning($enabled, true);
        $this->storeTuning($this->accountAtMark('disabled@example.com', 2), false);
        $this->accountAtMark('caught-up@example.com', self::CEILING);

        $marks = $this->sets()->findBelowMark(self::CEILING, new \DateTimeImmutable(self::NOW));
        ksort($marks);

        self::assertSame([$unset->requireId() => 3, $enabled->requireId() => 5], $marks);
    }

    public function testFindDisabledBelowMarkHoldsOnlyActiveDisabledAccountsBelowTheCeiling(): void
    {
        $disabled = $this->accountAtMark('disabled@example.com', 2);
        $this->storeTuning($disabled, false);
        $this->storeTuning($this->accountAtMark('disabled-caught-up@example.com', self::CEILING), false);
        $suspended = $this->accountAtMark('disabled-suspended@example.com', 1);
        $this->storeTuning($suspended, false);
        $suspended->suspend();
        $this->storeTuning($this->accountAtMark('enabled@example.com', 4), true);
        $this->accountAtMark('unset@example.com', 3);
        $this->entityManager->flush();

        $userIds = $this->sets()->findDisabledBelowMark(self::CEILING, new \DateTimeImmutable(self::NOW));

        self::assertSame([$disabled->requireId()], $userIds);
    }

    public function testFindStreamPrecisionsHoldsOnlyTheAskedOwnersThatHaveASettingsRow(): void
    {
        $narrowest = $this->accountAtMark('narrowest@example.com', 0);
        $this->storeTuning($narrowest, false, ForYouStreamPrecision::Narrowest);
        $defaulted = $this->accountAtMark('defaulted@example.com', 0);
        $this->entityManager->persist(new RecommendationSettings($defaulted));
        $unset = $this->accountAtMark('unset@example.com', 0);
        $this->storeTuning($this->accountAtMark('not-asked@example.com', 0), true, ForYouStreamPrecision::Widest);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $precisions = $this->sets()->findStreamPrecisions(
            [$narrowest->requireId(), $defaulted->requireId(), $unset->requireId()],
        );
        ksort($precisions);

        self::assertSame([
            $narrowest->requireId() => ForYouStreamPrecision::Narrowest,
            $defaulted->requireId() => ForYouStreamPrecision::Wide,
        ], $precisions);
    }

    public function testFindStreamPrecisionsForNoOwnersIsEmpty(): void
    {
        self::assertSame([], $this->sets()->findStreamPrecisions([]));
    }

    private function sets(): InterestTermSetRepository
    {
        $sets = self::getContainer()->get(InterestTermSetRepository::class);
        self::assertInstanceOf(InterestTermSetRepository::class, $sets);

        return $sets;
    }

    private function accountAtMark(string $email, int $mark): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $user->approve(new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($user);
        $at = new \DateTimeImmutable('2026-11-01 00:00:00');
        $this->entityManager->persist(new InterestTermSet($user, $at, $at, $mark));
        $this->entityManager->flush();

        return $user;
    }

    private function storeTuning(
        User $user,
        bool $enabled,
        ForYouStreamPrecision $precision = ForYouStreamPrecision::Balanced,
    ): void {
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        self::assertInstanceOf(ApiKeyCipher::class, $cipher);
        (new RecommendationRunFixtures($this->entityManager, $cipher))->storeForYouStreamTuning(
            $user,
            new ForYouStreamTuning($enabled, $precision),
        );
    }
}
