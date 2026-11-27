<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream\Model;

/** What an extraction call settled to: the storable terms, or the unusable reply its caller retries. */
final readonly class InterestTermOutcomeModel
{
    private function __construct(public ?ExtractedTermsModel $extracted, public ?string $unusableReply)
    {
    }

    public static function usable(ExtractedTermsModel $extracted): self
    {
        return new self($extracted, null);
    }

    public static function unusable(string $reply): self
    {
        return new self(null, $reply);
    }

    public function requireExtracted(): ExtractedTermsModel
    {
        return $this->extracted ?? throw new \LogicException('An unusable outcome carries no extracted terms.');
    }
}
