<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\ProfileRun;
use App\Entity\StoredProfile;
use App\Enum\ProfileRunOutcome;
use App\Service\Recommendation\Exception\RecommendationTickLockLostException;
use App\Service\Recommendation\Profile\Pass\ProfileTick;
use App\Service\Recommendation\Profile\ProfileRunDistiller\ProfileRunDistillerInterface;
use App\Service\Recommendation\Run\ProviderCallHeartbeat\TickLockKeepalive;
use App\Service\Recommendation\Settings\RecommendationSettingsWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/** A running profile run's model call: a usable reply replaces the stored profile, an unusable one is retried. */
final readonly class ProfileGeneration
{
    public const string NO_USABLE_REPLY = 'The model gave no usable profile in %d attempts.';

    public function __construct(
        private ProfileRunDistillerInterface $distiller,
        private TickLockKeepalive $keepalive,
        private RecommendationSettingsWriter $settingsWriter,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /** @throws RecommendationTickLockLostException when another process took the lock during the call */
    public function advance(ProfileTick $tick): void
    {
        $outcome = $this->distiller->distill($tick);
        if ($this->keepalive->hasLostTheLock()) {
            throw new RecommendationTickLockLostException();
        }

        if (!$outcome->usable) {
            $this->retryOrFail($tick->profileRun, $outcome->requireUnusableReply());

            return;
        }

        $this->store(
            $tick->profileRun,
            $outcome->profileText ?? throw new \LogicException('A usable outcome carries its profile text.'),
        );
    }

    private function retryOrFail(ProfileRun $profileRun, string $unusableReply): void
    {
        $profileRun->recordInvalidReply($unusableReply);
        if ($profileRun->hasExhaustedAttempts()) {
            $profileRun->fail(\sprintf(self::NO_USABLE_REPLY, ProfileRun::MAX_ATTEMPTS), $this->clock->now());
        }

        $this->entityManager->flush();
    }

    private function store(ProfileRun $profileRun, string $profileText): void
    {
        $now = $this->clock->now();
        $profileRun->complete(ProfileRunOutcome::Generated, $now);
        $this->settingsWriter->storeProfile(
            $profileRun->getUser(),
            new StoredProfile($profileText, $now, $profileRun->getProviderHost(), $profileRun->getModel()),
        );
        $this->entityManager->flush();
    }
}
