<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream\Pass;

use App\Enum\ForYouStreamPrecision;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermsModel;

/** One sweep run: the accounts walking now with their terms and precisions, and the one stemming they share. */
final class StreamSweepPass
{
    /** @var array<int, InterestTermsModel> */
    private array $termsByUserId = [];

    /** @var array<int, ForYouStreamPrecision> */
    private array $precisionsByUserId = [];

    /** @var list<int> */
    private array $userIds = [];

    public function __construct(
        public readonly EntryStemming $stemming,
        public readonly \DateTimeImmutable $publishedSince,
    ) {
    }

    /**
     * @param array<int, InterestTermsModel> $termsByUserId
     * @param array<int, ForYouStreamPrecision> $precisionsByUserId
     */
    public function join(array $termsByUserId, array $precisionsByUserId): void
    {
        $this->termsByUserId = $this->termsByUserId + $termsByUserId;
        $this->precisionsByUserId = $this->precisionsByUserId + $precisionsByUserId;
        $this->userIds = array_keys($this->termsByUserId);
    }

    /** @return list<int> */
    public function collectUserIds(): array
    {
        return $this->userIds;
    }

    public function requireTerms(int $userId): InterestTermsModel
    {
        return $this->termsByUserId[$userId]
            ?? throw new \LogicException(\sprintf('User %d is not walking in this pass.', $userId));
    }

    public function readMinimumScore(int $userId): float
    {
        return ($this->precisionsByUserId[$userId] ?? ForYouStreamPrecision::DEFAULT)->minimumScore();
    }
}
