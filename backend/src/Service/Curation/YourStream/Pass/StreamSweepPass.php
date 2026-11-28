<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream\Pass;

use App\Entity\YourStreamTuning;
use App\Service\Curation\YourStream\Model\StreamStrengthsModel;

/**
 * One sweep run: the accounts walking now, the terms and tunings of those whose stream is on, and the one stemming
 * they share. An account whose stream is off walks without terms, so its mark moves while it matches nothing.
 */
final class StreamSweepPass
{
    /** @var array<int, StreamStrengthsModel> */
    private array $termsByUserId = [];

    /** @var array<int, YourStreamTuning> */
    private array $tuningsByUserId = [];

    public function __construct(
        public readonly EntryStemming $stemming,
        public readonly \DateTimeImmutable $publishedSince,
    ) {
    }

    /**
     * @param array<int, StreamStrengthsModel> $termsByUserId
     * @param array<int, YourStreamTuning>     $tuningsByUserId one per walking account
     */
    public function join(array $termsByUserId, array $tuningsByUserId): void
    {
        $this->termsByUserId = $this->termsByUserId + $termsByUserId;
        $this->tuningsByUserId = $this->tuningsByUserId + $tuningsByUserId;
    }

    /** @return list<int> */
    public function collectUserIds(): array
    {
        return array_keys($this->tuningsByUserId);
    }

    /** @return list<int> */
    public function collectMatchingUserIds(): array
    {
        return array_keys($this->termsByUserId);
    }

    public function requireTerms(int $userId): StreamStrengthsModel
    {
        return $this->termsByUserId[$userId]
            ?? throw new \LogicException(\sprintf('User %d is not matching in this pass.', $userId));
    }

    public function readMinimumScore(int $userId): float
    {
        return ($this->tuningsByUserId[$userId] ?? YourStreamTuning::defaults())->getPrecision()->minimumScore();
    }
}
