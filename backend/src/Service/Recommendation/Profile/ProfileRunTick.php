<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\AiProviderSettings;
use App\Entity\ProfileRun;
use App\Enum\RunStatus;
use App\Service\Ai\Exception\AiKeyUnreadableException;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderRateLimitedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Exception\RetryableProviderException;
use App\Service\Recommendation\Exception\RecommendationTickLockLostException;
use App\Service\Recommendation\Pool\RecommendationHistoryLoader;
use App\Service\Recommendation\Profile\Pass\ProfileTick;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Service\Recommendation\Run\RecommendationRunAdvancer;
use App\Service\Recommendation\Settings\RecommendationSettingsResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One tick of a profile run under the caller's lock: open it, or make its one model call. A provider failure is
 * recorded on the run and never thrown.
 */
final readonly class ProfileRunTick
{
    public function __construct(
        private RecommendationSettingsResolver $settingsResolver,
        private RecommendationHistoryLoader $historyLoader,
        private ProfileRunOpening $opening,
        private ProfileGeneration $generation,
        private ProfileRunFailure $failure,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** Rethrows any other failure once it is recorded as a strike, so the driver still logs it. */
    public function advance(ProfileRun $profileRun, ?AiProviderSettings $connection, TickDriver $driver): void
    {
        try {
            if (null === $connection) {
                $this->failure->fail($profileRun, ProfileConnections::MISSING);

                return;
            }

            $this->advanceRecordingProviderFailures($this->tickFor($profileRun, $connection, $driver));
        } catch (\Throwable $exception) {
            $this->failure->recordUnexpectedFailure($profileRun);

            throw $exception;
        }
    }

    private function advanceRecordingProviderFailures(ProfileTick $tick): void
    {
        try {
            $this->advanceWithin($tick);
        } catch (RecommendationTickLockLostException) {
            $this->entityManager->refresh($tick->profileRun);
        } catch (
            ProviderUnreachableException | CredentialsRejectedException | RetryableProviderException
            | ProviderRateLimitedException $exception
        ) {
            $this->failure->recordTransportFailure($tick, $exception->getMessage());
        } catch (AiKeyUnreadableException) {
            $this->failure->fail($tick->profileRun, RecommendationRunAdvancer::KEY_UNREADABLE);
        }
    }

    private function tickFor(ProfileRun $profileRun, AiProviderSettings $connection, TickDriver $driver): ProfileTick
    {
        $user = $profileRun->getUser();
        $settings = $this->settingsResolver->forAccount($user)->forConnection($connection);

        return new ProfileTick(
            $profileRun,
            $connection,
            $settings,
            $this->historyLoader->load($user->requireId(), $settings),
            $driver,
        );
    }

    private function advanceWithin(ProfileTick $tick): void
    {
        if (RunStatus::Pending === $tick->profileRun->getStatus()) {
            $this->opening->open($tick);
        }

        if (RunStatus::Running === $tick->profileRun->getStatus()) {
            $this->generation->advance($tick);
        }
    }
}
