<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream\Model;

/** What an extraction call settled to: the storable stems, or the unusable reply its caller retries. */
final readonly class InterestTermOutcomeModel
{
    /** @param list<TermStemModel> $stems */
    private function __construct(public array $stems, public ?string $unusableReply)
    {
    }

    /** @param list<TermStemModel> $stems */
    public static function usable(array $stems): self
    {
        return new self($stems, null);
    }

    public static function unusable(string $reply): self
    {
        return new self([], $reply);
    }
}
