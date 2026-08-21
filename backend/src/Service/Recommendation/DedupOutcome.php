<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * What RecommendationDedupResolver settled a dedup call to, handed back for the
 * advancer to write. A usable reply -- or an all-pruned pool with nothing left
 * to check -- carries the ranked list to finalize; an unusable one carries the
 * offending reply and the undeduped list to degrade to, so the advancer can run
 * its cross-tick retry-or-degrade envelope. Transport failures never reach
 * here: the resolver throws them, exactly as RecommendationBatchWave does.
 */
final readonly class DedupOutcome
{
    /**
     * @param list<array{id: int, score: int, reason: string}> $ranked
     */
    private function __construct(
        public bool $usable,
        public array $ranked,
        private ?string $unusableReply,
    ) {
    }

    /**
     * @param list<array{id: int, score: int, reason: string}> $ranked
     */
    public static function finalizeWith(array $ranked): self
    {
        return new self(true, $ranked, null);
    }

    /**
     * @param list<array{id: int, score: int, reason: string}> $undeduped
     */
    public static function unusable(string $reply, array $undeduped): self
    {
        return new self(false, $undeduped, $reply);
    }

    /**
     * The reply the dedup call could not use, for the advancer's retry-or-degrade
     * envelope. Only an unusable outcome has one.
     */
    public function requireUnusableReply(): string
    {
        return $this->unusableReply
            ?? throw new \LogicException('A usable dedup outcome has no invalid reply to retry.');
    }
}
