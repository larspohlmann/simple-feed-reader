<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Feed\Model;

use App\Entity\ProfileRun;
use App\Entity\RecommendationRun;
use App\Entity\StoryAiRun;
use App\Enum\AiRunKind;
use App\Enum\RunStatus;

/** A story run records no status, error, retries or completion, so those stay null rather than invented. */
final readonly class DebugLogRunModel
{
    public function __construct(
        public AiRunKind $kind,
        public int $id,
        public ?RunStatus $status,
        public ?string $error,
        public ?RunCallCountersModel $counters,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $completedAt,
    ) {
    }

    public static function fromRecommendationRun(RecommendationRun $run): self
    {
        return new self(
            AiRunKind::ForYou,
            $run->requireId(),
            $run->getStatus(),
            $run->getError(),
            new RunCallCountersModel(
                $run->getAttempts(),
                RecommendationRun::MAX_ATTEMPTS,
                $run->getTransportFailures(),
                RecommendationRun::MAX_TRANSPORT_FAILURES,
            ),
            $run->getCreatedAt(),
            $run->getCompletedAt(),
        );
    }

    public static function fromProfileRun(ProfileRun $run): self
    {
        return new self(
            AiRunKind::Profile,
            $run->requireId(),
            $run->getStatus(),
            $run->getError(),
            new RunCallCountersModel(
                $run->getAttempts(),
                ProfileRun::MAX_ATTEMPTS,
                $run->getTransportFailures(),
                ProfileRun::MAX_TRANSPORT_FAILURES,
            ),
            $run->getCreatedAt(),
            $run->getCompletedAt(),
        );
    }

    public static function fromStoryAiRun(StoryAiRun $run): self
    {
        return new self(AiRunKind::Story, $run->requireId(), null, null, null, $run->getCreatedAt(), null);
    }
}
