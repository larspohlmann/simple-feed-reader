<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream\Pass;

use App\Service\Curation\Recommendation\Stream\Model\InterestTermsModel;

/** One sweep run: the accounts walking now with their terms, and the one stemming they share. */
final class StreamSweepPass
{
    /** @var array<int, InterestTermsModel> */
    private array $termsByUserId = [];

    /** @var list<int> */
    private array $userIds = [];

    public function __construct(
        public readonly EntryStemming $stemming,
        public readonly \DateTimeImmutable $publishedSince,
    ) {
    }

    /** @param array<int, InterestTermsModel> $termsByUserId */
    public function join(array $termsByUserId): void
    {
        $this->termsByUserId = $this->termsByUserId + $termsByUserId;
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
}
