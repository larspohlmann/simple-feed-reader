<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * What RecommendationProfileDistiller settled a distillation call to, handed
 * back for the advancer's distillTick to write (#493). A usable reply
 * carries the profile text the resolver already cached on settings; an
 * unusable one carries the offending reply, so the advancer can run its
 * cross-tick retry-or-degrade envelope exactly as the dedup phase's
 * DedupOutcome does. Transport failures never reach here: the resolver
 * throws them, exactly as RecommendationDedupResolver does.
 */
final readonly class ProfileDistillationOutcome
{
    private function __construct(
        public bool $usable,
        public ?string $profileText,
        private ?string $unusableReply,
    ) {
    }

    public static function usable(string $profileText): self
    {
        return new self(true, $profileText, null);
    }

    public static function unusable(string $reply): self
    {
        return new self(false, null, $reply);
    }

    /**
     * The reply the distillation call could not use, for the advancer's
     * retry-or-degrade envelope. Only an unusable outcome has one.
     */
    public function requireUnusableReply(): string
    {
        return $this->unusableReply
            ?? throw new \LogicException('A usable profile distillation outcome has no invalid reply to retry.');
    }
}
