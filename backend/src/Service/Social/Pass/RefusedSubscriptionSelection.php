<?php

declare(strict_types=1);

namespace App\Service\Social\Pass;

use App\Entity\Subscription;
use App\Service\Social\Model\RefusedFeedPositionModel;
use App\Service\Social\Model\RefusedProfileSubscriptionModel;

/**
 * One switch run's pick of refused subscriptions, in keyset order from where the last run stopped: actors of unknown
 * visibility up to what the run may ask about, and profiles already known hidden up to what it may move.
 */
final class RefusedSubscriptionSelection
{
    /** @var list<RefusedProfileSubscriptionModel> */
    private array $candidates = [];

    /** @var array<string, string> actor => actor, so each counts once */
    private array $unknownActors = [];

    private int $knownHidden = 0;

    public function __construct(
        private readonly int $askLimit,
        private readonly int $moveLimit,
        private ?RefusedFeedPositionModel $position,
    ) {
    }

    public function admits(RefusedProfileSubscriptionModel $candidate): bool
    {
        if (null !== $candidate->knownHidden) {
            return $this->knownHidden < $this->moveLimit;
        }

        return isset($this->unknownActors[$candidate->actor]) || !$this->isAskFull();
    }

    public function add(RefusedProfileSubscriptionModel $candidate): void
    {
        $this->candidates[] = $candidate;
        if (null === $candidate->knownHidden) {
            $this->unknownActors[$candidate->actor] = $candidate->actor;
        } else {
            ++$this->knownHidden;
        }
        $this->passed($candidate->subscription);
    }

    public function passed(Subscription $subscription): void
    {
        $this->position = RefusedFeedPositionModel::of($subscription);
    }

    public function restart(): void
    {
        $this->position = null;
    }

    public function position(): ?RefusedFeedPositionModel
    {
        return $this->position;
    }

    /** @return list<RefusedProfileSubscriptionModel> */
    public function candidates(): array
    {
        return $this->candidates;
    }

    /** @return list<string> */
    public function unknownActors(): array
    {
        return array_values($this->unknownActors);
    }

    public function isAskFull(): bool
    {
        return \count($this->unknownActors) >= $this->askLimit;
    }
}
