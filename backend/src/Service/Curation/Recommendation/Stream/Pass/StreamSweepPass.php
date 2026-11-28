<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream\Pass;

use App\Entity\ForYouStreamTuning;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermsModel;

/**
 * One sweep run: the accounts walking now, the terms and tunings of those whose stream is on, and the one stemming
 * they share. An account whose stream is off walks without terms, so its mark moves while it matches nothing.
 */
final class StreamSweepPass
{
    /** @var list<int> */
    private array $userIds = [];

    /** @var array<int, InterestTermsModel> */
    private array $termsByUserId = [];

    /** @var array<int, ForYouStreamTuning> */
    private array $tuningsByUserId = [];

    public function __construct(
        public readonly EntryStemming $stemming,
        public readonly \DateTimeImmutable $publishedSince,
    ) {
    }

    /**
     * @param list<int> $group
     * @param array<int, InterestTermsModel> $termsByUserId
     * @param array<int, ForYouStreamTuning> $tuningsByUserId
     */
    public function join(array $group, array $termsByUserId, array $tuningsByUserId): void
    {
        $this->userIds = [...$this->userIds, ...$group];
        $this->termsByUserId = $this->termsByUserId + $termsByUserId;
        $this->tuningsByUserId = $this->tuningsByUserId + $tuningsByUserId;
    }

    /** @return list<int> */
    public function collectUserIds(): array
    {
        return $this->userIds;
    }

    /** @return list<int> */
    public function collectMatchingUserIds(): array
    {
        return array_keys($this->termsByUserId);
    }

    public function requireTerms(int $userId): InterestTermsModel
    {
        return $this->termsByUserId[$userId]
            ?? throw new \LogicException(\sprintf('User %d is not matching in this pass.', $userId));
    }

    public function readMinimumScore(int $userId): float
    {
        return ($this->tuningsByUserId[$userId] ?? ForYouStreamTuning::defaults())->getPrecision()->minimumScore();
    }
}
