<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream\Model;

/** What an extraction call settled to: the weighted terms, or the unusable reply its caller retries. */
final readonly class InterestTermOutcomeModel
{
    /** @param list<InterestTermModel> $terms */
    private function __construct(public bool $usable, private array $terms, private ?string $unusableReply)
    {
    }

    /** @param list<InterestTermModel> $terms */
    public static function usable(array $terms): self
    {
        return new self(true, $terms, null);
    }

    public static function unusable(string $reply): self
    {
        return new self(false, [], $reply);
    }

    /** @return list<InterestTermModel> */
    public function requireTerms(): array
    {
        if (!$this->usable) {
            throw new \LogicException('An unusable interest-term outcome has no terms.');
        }

        return $this->terms;
    }

    public function requireUnusableReply(): string
    {
        return $this->unusableReply
            ?? throw new \LogicException('A usable interest-term outcome has no invalid reply to retry.');
    }
}
