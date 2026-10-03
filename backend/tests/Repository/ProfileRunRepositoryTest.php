<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\ProfileRun;
use App\Entity\User;
use App\Enum\ProfileRunOutcome;
use App\Enum\ProfileRunTrigger;
use App\Repository\ProfileRunRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;

final class ProfileRunRepositoryTest extends DbTestCase
{
    use SeedsUsers;

    public function testTheActiveRunIsTheAccountsOwnUnfinishedOne(): void
    {
        $owner = $this->user('profile-runs-active@example.test');
        $this->completed($owner, 'fp-done');
        $running = $this->running($owner, 'fp-running');
        $this->running($this->user('profile-runs-other@example.test'), 'fp-other');

        self::assertSame($running->getId(), $this->profileRuns()->findActiveForUser($owner)?->getId());
    }

    public function testTheLatestRunIsTheNewestWhateverItsStatus(): void
    {
        $owner = $this->user('profile-runs-latest@example.test');
        $this->running($owner, 'fp-running');
        $failed = $this->failed($owner);

        self::assertSame($failed->getId(), $this->profileRuns()->findLatestForUser($owner)?->getId());
    }

    public function testTheLatestCompletedFingerprintSkipsRunningAndFailedRuns(): void
    {
        $owner = $this->user('profile-runs-fingerprint@example.test');
        $this->completed($owner, 'fp-older');
        $this->completed($owner, 'fp-newest-completed');
        $this->failed($owner);
        $this->running($owner, 'fp-running');

        self::assertSame('fp-newest-completed', $this->profileRuns()->latestCompletedFingerprintFor($owner));
    }

    public function testAnAccountWithoutACompletedRunHasNoFingerprint(): void
    {
        $owner = $this->user('profile-runs-no-fingerprint@example.test');
        $this->running($owner, 'fp-running');

        self::assertNull($this->profileRuns()->latestCompletedFingerprintFor($owner));
    }

    public function testEveryActiveRunComesOldestFirst(): void
    {
        $older = $this->running($this->user('profile-runs-all-a@example.test'), 'fp-a');
        $this->completed($this->user('profile-runs-all-b@example.test'), 'fp-b');
        $newer = $this->running($this->user('profile-runs-all-c@example.test'), 'fp-c');

        $ids = array_map(
            static fn (ProfileRun $profileRun): ?int => $profileRun->getId(),
            $this->profileRuns()->findAllActive(),
        );

        self::assertSame([$older->getId(), $newer->getId()], $ids);
        self::assertTrue($this->profileRuns()->hasActiveRun());
    }

    public function testNoActiveRunAnywhereReadsAsNone(): void
    {
        $this->completed($this->user('profile-runs-none@example.test'), 'fp-done');

        self::assertFalse($this->profileRuns()->hasActiveRun());
    }

    public function testTheNewestIdsStopAtTheLimit(): void
    {
        $owner = $this->user('profile-runs-ids@example.test');
        $this->completed($owner, 'fp-1');
        $second = $this->completed($owner, 'fp-2');
        $third = $this->failed($owner);

        self::assertSame(
            [$third->requireId(), $second->requireId()],
            $this->profileRuns()->findNewestIdsForUser($owner, 2),
        );
    }

    private function running(User $owner, string $fingerprint): ProfileRun
    {
        $profileRun = new ProfileRun(
            $owner,
            ProfileRunTrigger::Scheduled,
            new \DateTimeImmutable('2026-10-03 09:00:00'),
        );
        $profileRun->start($fingerprint, 'llm.example.test', 'qwen3-14b');
        $this->entityManager->persist($profileRun);
        $this->entityManager->flush();

        return $profileRun;
    }

    private function completed(User $owner, string $fingerprint): ProfileRun
    {
        $profileRun = $this->running($owner, $fingerprint);
        $profileRun->complete(ProfileRunOutcome::Generated, new \DateTimeImmutable('2026-10-03 09:02:00'));
        $this->entityManager->flush();

        return $profileRun;
    }

    private function failed(User $owner): ProfileRun
    {
        $profileRun = new ProfileRun($owner, ProfileRunTrigger::Manual, new \DateTimeImmutable('2026-10-03 09:00:00'));
        $profileRun->fail('No connection can build your profile.', new \DateTimeImmutable('2026-10-03 09:00:01'));
        $this->entityManager->persist($profileRun);
        $this->entityManager->flush();

        return $profileRun;
    }

    private function profileRuns(): ProfileRunRepository
    {
        /** @var ProfileRunRepository $profileRuns */
        $profileRuns = $this->entityManager->getRepository(ProfileRun::class);

        return $profileRuns;
    }
}
