<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Model;

/** What a distillation call settled to: the profile text, or the unusable reply DistillationPhase retries. */
final readonly class ProfileDistillationOutcomeModel
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

    public function requireUnusableReply(): string
    {
        return $this->unusableReply
            ?? throw new \LogicException('A usable profile distillation outcome has no invalid reply to retry.');
    }
}
