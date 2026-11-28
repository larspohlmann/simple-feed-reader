<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\InterestTermSet;
use App\Entity\RecommendationSettings;
use App\Entity\User;
use App\Entity\YourStreamTuning;
use App\Enum\YourStreamPrecision;
use App\Repository\InterestTermSetRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;

final class InterestTermSetRepositoryTest extends DbTestCase
{
    private const int CEILING = 10;
    private const string NOW = '2026-11-27 10:00:00';

    public function testFindBelowMarkHoldsEveryActiveAccountBelowTheCeilingWhateverItsSwitch(): void
    {
        $unset = $this->accountAtMark('unset@example.com', 3);
        $enabled = $this->accountAtMark('enabled@example.com', 5);
        $this->storeTuning($enabled, true);
        $disabled = $this->accountAtMark('disabled@example.com', 2);
        $this->storeTuning($disabled, false);
        $suspended = $this->accountAtMark('suspended@example.com', 1);
        $suspended->suspend();
        $this->accountAtMark('caught-up@example.com', self::CEILING);
        $this->entityManager->flush();

        $marks = $this->sets()->findBelowMark(self::CEILING, new \DateTimeImmutable(self::NOW));
        ksort($marks);

        self::assertSame([$unset->requireId() => 3, $enabled->requireId() => 5, $disabled->requireId() => 2], $marks);
    }

    public function testFindStreamTuningsHoldsEachAskedOwnerAndTheDefaultsWithoutASettingsRow(): void
    {
        $narrowest = $this->accountAtMark('narrowest@example.com', 0);
        $this->storeTuning($narrowest, false, YourStreamPrecision::Narrowest);
        $defaulted = $this->accountAtMark('defaulted@example.com', 0);
        $this->entityManager->persist(new RecommendationSettings($defaulted));
        $unset = $this->accountAtMark('unset@example.com', 0);
        $this->storeTuning($this->accountAtMark('not-asked@example.com', 0), true, YourStreamPrecision::Widest);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $tunings = $this->sets()->findStreamTunings(
            [$narrowest->requireId(), $defaulted->requireId(), $unset->requireId()],
        );
        ksort($tunings);

        self::assertEquals([
            $narrowest->requireId() => new YourStreamTuning(false, YourStreamPrecision::Narrowest),
            $defaulted->requireId() => YourStreamTuning::defaults(),
            $unset->requireId() => YourStreamTuning::defaults(),
        ], $tunings);
    }

    public function testFindStreamTuningsForNoOwnersIsEmpty(): void
    {
        self::assertSame([], $this->sets()->findStreamTunings([]));
    }

    public function testHasEnabledStreamHoldsForATermSetWithoutSettingsOrWithTheSwitchOn(): void
    {
        $unset = $this->accountAtMark('unset@example.com', 0);
        $enabled = $this->accountAtMark('enabled@example.com', 0);
        $this->storeTuning($enabled, true);
        $disabled = $this->accountAtMark('disabled@example.com', 0);
        $this->storeTuning($disabled, false);
        $withoutTerms = new User('no-terms@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($withoutTerms);
        $this->entityManager->flush();

        self::assertTrue($this->sets()->hasEnabledStreamForUser($unset->requireId()));
        self::assertTrue($this->sets()->hasEnabledStreamForUser($enabled->requireId()));
        self::assertFalse($this->sets()->hasEnabledStreamForUser($disabled->requireId()));
        self::assertFalse($this->sets()->hasEnabledStreamForUser($withoutTerms->requireId()));
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
        YourStreamPrecision $precision = YourStreamPrecision::Balanced,
    ): void {
        RecommendationRunFixtures::fromContainer(self::getContainer())
            ->storeYourStreamTuning($user, new YourStreamTuning($enabled, $precision));
    }
}
