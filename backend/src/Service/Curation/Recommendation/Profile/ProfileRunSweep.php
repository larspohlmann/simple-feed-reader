<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\ProfileRun;
use App\Entity\User;
use App\Enum\ProfileRunTrigger;
use App\Repository\ProfileRunRepository;
use App\Service\Recommendation\Run\Model\TickDriver;
use Psr\Log\LoggerInterface;

/** Scheduled profiles: start the due runs, and tick each active one, for the cron sweep, the worker and the drainer. */
final readonly class ProfileRunSweep
{
    public function __construct(
        private DueProfileRunFinder $finder,
        private ProfileRunStarter $starter,
        private ProfileRunAdvancer $advancer,
        private ProfileRunRepository $profileRuns,
        private LoggerInterface $logger,
    ) {
    }

    public function startDueRuns(): int
    {
        $started = 0;
        foreach ($this->finder->due() as $user) {
            $started += $this->startOne($user);
        }

        return $started;
    }

    /** @return list<ProfileRun> */
    public function activeRuns(): array
    {
        return $this->profileRuns->findAllActive();
    }

    public function advanceOne(ProfileRun $profileRun, TickDriver $driver): void
    {
        try {
            $this->advancer->advance($profileRun, $driver);
        } catch (\Throwable $exception) {
            // The floor: a tick records every provider failure itself, so whatever lands here is unexpected.
            $this->logger->error('Profile sweep: unexpected failure advancing a profile run.', [
                'profileRunId' => $profileRun->getId(),
                'exception' => $exception,
            ]);
        }
    }

    /** One account's failure must not cost the others their run, nor the cron sweep its recommendation pass. */
    private function startOne(User $user): int
    {
        try {
            $this->starter->start($user, ProfileRunTrigger::Scheduled);

            return 1;
        } catch (\Throwable $exception) {
            $this->logger->error('Profile sweep: starting a due profile run failed.', [
                'userId' => $user->getId(),
                'exception' => $exception,
            ]);

            return 0;
        }
    }
}
