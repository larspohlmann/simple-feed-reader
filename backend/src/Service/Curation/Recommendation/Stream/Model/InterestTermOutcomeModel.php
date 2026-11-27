<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream\Model;

/** What an extraction call settled to: the storable stems, or the unusable reply its caller retries. */
final readonly class InterestTermOutcomeModel
{
    /** @param list<TermStemModel> $stems */
    private function __construct(public bool $usable, private array $stems, private ?string $unusableReply)
    {
    }

    /** @param list<TermStemModel> $stems */
    public static function usable(array $stems): self
    {
        return new self(true, $stems, null);
    }

    public static function unusable(string $reply): self
    {
        return new self(false, [], $reply);
    }

    /** @return list<TermStemModel> */
    public function requireStems(): array
    {
        if (!$this->usable) {
            throw new \LogicException('An unusable interest-term outcome has no stems.');
        }

        return $this->stems;
    }

    public function requireUnusableReply(): string
    {
        return $this->unusableReply
            ?? throw new \LogicException('A usable interest-term outcome has no invalid reply to retry.');
    }
}
