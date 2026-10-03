<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Profile\ProfileForRun;

use App\Entity\ProfileRun;
use App\Entity\User;
use App\Enum\ProfileRunOutcome;
use App\Enum\ProfileRunTrigger;
use App\Enum\RunStatus;
use App\Repository\ProfileRunRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Profile\Model\RunProfileState;
use App\Service\Recommendation\Profile\ProfileForRun\ProfileForRunInterface;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;

final class ProvisionedProfileForRunTest extends DbTestCase
{
    use SeedsUsers;

    private const string RUN_CREATED_AT = '2026-10-03 09:00:00';

    private RecommendationRunFixtures $fixtures;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->owner = $this->user('profile-for-run@example.test');
        $this->fixtures->seedReadyAiSettingsFor($this->owner, 'qwen3-14b');
    }

    public function testAStoredProfileIsReadyAndStartsNothing(): void
    {
        $this->fixtures->storeProfile($this->owner, 'Likes rail and maps.');

        $profile = $this->profiles()->profileFor($this->owner, $this->runCreatedAt());

        self::assertSame(RunProfileState::Ready, $profile->state);
        self::assertSame('Likes rail and maps.', $profile->text);
        self::assertNull($this->profileRuns()->findLatestForUser($this->owner));
    }

    public function testWithoutAProfileItStartsAProfileRunForTheRecommendationAndSaysBuilding(): void
    {
        $profile = $this->profiles()->profileFor($this->owner, $this->runCreatedAt());

        self::assertSame(RunProfileState::Building, $profile->state);
        self::assertSame(
            ProfileRunTrigger::Recommendation,
            $this->profileRuns()->findActiveForUser($this->owner)?->getTrigger(),
        );
        self::assertTrue($this->profiles()->isBuildingFor($this->owner));
    }

    public function testAnActiveProfileRunFromBeforeTheRunIsWaitedOnNotDuplicated(): void
    {
        $earlier = $this->profileRunAt('2026-10-03 08:00:00');

        $profile = $this->profiles()->profileFor($this->owner, $this->runCreatedAt());

        self::assertSame(RunProfileState::Building, $profile->state);
        self::assertSame($earlier->getId(), $this->profileRuns()->findLatestForUser($this->owner)?->getId());
    }

    public function testARunningProfileRunIsWaitedOn(): void
    {
        $this->profileRunAt('2026-10-03 08:00:00')->start('fingerprint', 'api.example.test', 'qwen3-14b');
        $this->entityManager->flush();

        $profile = $this->profiles()->profileFor($this->owner, $this->runCreatedAt());

        self::assertSame(RunProfileState::Building, $profile->state);
    }

    public function testAProfileRunThatFailedSinceTheRunStartedIsAFailure(): void
    {
        $this->profileRunAt('2026-10-03 09:00:30')
            ->fail('The AI provider at x failed: y', new \DateTimeImmutable('2026-10-03 09:01:00'));
        $this->entityManager->flush();

        $profile = $this->profiles()->profileFor($this->owner, $this->runCreatedAt());

        self::assertSame(RunProfileState::Failed, $profile->state);
        self::assertSame('The AI provider at x failed: y', $profile->error);
    }

    /** The database keeps whole seconds, so the run's own profile run often shares its creation second. */
    public function testAProfileRunStartedInTheRunsOwnSecondIsItsAnswer(): void
    {
        $this->profileRunAt(self::RUN_CREATED_AT)
            ->fail('The AI provider at x failed: y', new \DateTimeImmutable('2026-10-03 09:00:01'));
        $this->entityManager->flush();

        $profile = $this->profiles()->profileFor($this->owner, $this->runCreatedAt());

        self::assertSame(RunProfileState::Failed, $profile->state);
    }

    public function testAProfileRunThatFailedBeforeTheRunStartedIsRetried(): void
    {
        $failed = $this->profileRunAt('2026-10-03 08:00:00');
        $failed->fail('old failure', new \DateTimeImmutable('2026-10-03 08:01:00'));
        $this->entityManager->flush();

        $profile = $this->profiles()->profileFor($this->owner, $this->runCreatedAt());

        self::assertSame(RunStatus::Pending, $this->profileRuns()->findLatestForUser($this->owner)?->getStatus());
        self::assertSame(RunProfileState::Building, $profile->state);
    }

    public function testAProfileRunThatFoundNoHistoryLetsTheRunGoOnWithoutAProfile(): void
    {
        $profileRun = $this->profileRunAt('2026-10-03 09:00:30');
        $profileRun->start('fingerprint', 'api.example.test', 'qwen3-14b');
        $profileRun->complete(ProfileRunOutcome::NoHistory, new \DateTimeImmutable('2026-10-03 09:00:31'));
        $this->entityManager->flush();

        $profile = $this->profiles()->profileFor($this->owner, $this->runCreatedAt());

        self::assertSame(RunProfileState::Ready, $profile->state);
        self::assertNull($profile->text);
        self::assertFalse($this->profiles()->isBuildingFor($this->owner));
    }

    private function profileRunAt(string $createdAt): ProfileRun
    {
        $profileRun = new ProfileRun($this->owner, ProfileRunTrigger::Scheduled, new \DateTimeImmutable($createdAt));
        $this->entityManager->persist($profileRun);
        $this->entityManager->flush();

        return $profileRun;
    }

    private function runCreatedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::RUN_CREATED_AT);
    }

    private function profiles(): ProfileForRunInterface
    {
        /** @var ProfileForRunInterface $profiles */
        $profiles = self::getContainer()->get(ProfileForRunInterface::class);

        return $profiles;
    }

    private function profileRuns(): ProfileRunRepository
    {
        /** @var ProfileRunRepository $profileRuns */
        $profileRuns = $this->entityManager->getRepository(ProfileRun::class);

        return $profileRuns;
    }
}
