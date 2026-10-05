<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Enum\ProfileRunOutcome;
use App\Enum\ProfileRunTrigger;
use App\Repository\ProfileRunRepository;
use App\Repository\RecommendationSettingsRepository;
use App\Service\Ai\Support\ProviderHost;
use App\Service\Recommendation\Profile\Model\ProfileInputsModel;
use App\Service\Recommendation\Profile\Pass\ProfileTick;
use App\Service\Recommendation\Profile\Support\ProfileInputFingerprint;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/** Starts a pending profile run; without history, or as a scheduled run with unchanged inputs, it ends right there. */
final readonly class ProfileRunOpening
{
    public function __construct(
        private ProfileRunRepository $profileRuns,
        private RecommendationSettingsRepository $recommendationSettings,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function open(ProfileTick $tick): void
    {
        $profileRun = $tick->profileRun;
        $inputs = new ProfileInputsModel($tick->history, []);
        $fingerprint = ProfileInputFingerprint::of($inputs, $tick->settings->historyCaps, $tick->connection);
        $unchanged = $this->isUnchangedScheduledRun($tick, $fingerprint);

        $profileRun->start($fingerprint, ProviderHost::of($tick->connection), $this->modelOf($tick));

        if ($tick->history->isEmpty()) {
            $profileRun->complete(ProfileRunOutcome::NoHistory, $this->clock->now());
        } elseif ($unchanged) {
            $profileRun->complete(ProfileRunOutcome::Unchanged, $this->clock->now());
        }

        $this->entityManager->flush();
    }

    private function isUnchangedScheduledRun(ProfileTick $tick, string $fingerprint): bool
    {
        $user = $tick->profileRun->getUser();

        return ProfileRunTrigger::Scheduled === $tick->profileRun->getTrigger()
            && null !== $this->recommendationSettings->findForUser($user)?->getStoredProfile()->getText()
            && $fingerprint === $this->profileRuns->latestCompletedFingerprintFor($user);
    }

    private function modelOf(ProfileTick $tick): string
    {
        return $tick->connection->getModel()
            ?? throw new \LogicException('A connection that builds profiles has a model.');
    }
}
