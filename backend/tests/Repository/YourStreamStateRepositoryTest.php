<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\RecommendationSettings;
use App\Entity\User;
use App\Entity\YourStreamState;
use App\Entity\YourStreamTuning;
use App\Enum\YourStreamPrecision;
use App\Repository\YourStreamStateRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;

final class YourStreamStateRepositoryTest extends DbTestCase
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

    public function testHasEnabledStreamHoldsForAStateWithoutSettingsOrWithTheSwitchOn(): void
    {
        $unset = $this->accountAtMark('unset@example.com', 0);
        $enabled = $this->accountAtMark('enabled@example.com', 0);
        $this->storeTuning($enabled, true);
        $disabled = $this->accountAtMark('disabled@example.com', 0);
        $this->storeTuning($disabled, false);
        $withoutState = new User('no-state@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($withoutState);
        $this->entityManager->flush();

        self::assertTrue($this->sets()->hasEnabledStreamForUser($unset->requireId()));
        self::assertTrue($this->sets()->hasEnabledStreamForUser($enabled->requireId()));
        self::assertFalse($this->sets()->hasEnabledStreamForUser($disabled->requireId()));
        self::assertFalse($this->sets()->hasEnabledStreamForUser($withoutState->requireId()));
    }

    public function testFindUserIdsDueForCheckHoldsActiveEnabledAccountsNotCheckedSinceTheCutoffOldestFirst(): void
    {
        $recent = $this->accountCheckedAt('recent@example.com', '2026-11-27 09:30:00');
        $older = $this->accountCheckedAt('older@example.com', '2026-11-27 08:00:00');
        $oldest = $this->accountCheckedAt('oldest@example.com', '2026-11-27 07:00:00');
        $atCutoff = $this->accountCheckedAt('at-cutoff@example.com', '2026-11-27 09:00:00');
        $neverChecked = $this->accountAtMark('never-checked@example.com', 0);
        $withoutState = $this->account('no-state@example.com');
        $enabled = $this->account('enabled@example.com');
        $this->storeTuning($enabled, true);
        $this->storeTuning($this->account('disabled@example.com'), false);
        $this->account('suspended@example.com')->suspend();
        $this->entityManager->flush();

        $due = $this->sets()->findUserIdsDueForCheck(
            new \DateTimeImmutable('2026-11-27 09:00:00'),
            new \DateTimeImmutable(self::NOW),
        );

        self::assertNotContains($recent->requireId(), $due);
        self::assertNotContains($atCutoff->requireId(), $due);
        $unchecked = [$neverChecked->requireId(), $withoutState->requireId(), $enabled->requireId()];
        self::assertSame([...$unchecked, $oldest->requireId(), $older->requireId()], $due);
    }

    public function testFindUserIdsDueForCheckSkipsAnAccountWhoseTrialEnded(): void
    {
        $user = $this->account('trial@example.com');
        $user->setTrialEndsAt(new \DateTimeImmutable('2026-11-01 00:00:00'));
        $this->entityManager->flush();

        self::assertSame([], $this->sets()->findUserIdsDueForCheck(
            new \DateTimeImmutable('2026-11-27 09:00:00'),
            new \DateTimeImmutable(self::NOW),
        ));
    }

    private function sets(): YourStreamStateRepository
    {
        $sets = self::getContainer()->get(YourStreamStateRepository::class);
        self::assertInstanceOf(YourStreamStateRepository::class, $sets);

        return $sets;
    }

    private function account(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $user->approve(new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function accountCheckedAt(string $email, string $checkedAt): User
    {
        $user = $this->account($email);
        $state = new YourStreamState($user, 0);
        $state->recordChecked(new \DateTimeImmutable($checkedAt));
        $this->entityManager->persist($state);
        $this->entityManager->flush();

        return $user;
    }

    private function accountAtMark(string $email, int $mark): User
    {
        $user = $this->account($email);
        $this->entityManager->persist(new YourStreamState($user, $mark));
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
