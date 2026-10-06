<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Factory;

use App\Service\Recommendation\Scoring\Model\ScoringReaderModel;
use App\Service\Recommendation\Scoring\Support\FittingPrefix;
use App\Service\Recommendation\Support\TokenEstimate;

/** The reader as one rerank query within a token budget: the question, then the guidance, then the profile. */
final readonly class RerankQueryFactory
{
    public const string QUESTION = 'Articles this reader would want to read, judged by the guidance and the reading '
        . 'profile below.';

    private const string GUIDANCE_LABEL = "\nGuidance: ";
    private const string PROFILE_LABEL = "\nProfile: ";

    public function create(ScoringReaderModel $reader, int $tokenBudget): string
    {
        $bytesFit = static fn (int $bytes): bool => TokenEstimate::ofLength($bytes) <= $tokenBudget;
        $opening = self::QUESTION . self::guidanceLine($reader->guidance, $bytesFit) . self::PROFILE_LABEL;

        return $opening . FittingPrefix::of(
            mb_scrub($reader->profile, 'UTF-8'),
            static fn (string $prefix): bool => $bytesFit(\strlen($opening) + \strlen($prefix)),
        );
    }

    /**
     * Fitted beside an empty profile: the profile's label must still fit once the guidance has taken the budget.
     *
     * @param \Closure(int): bool $bytesFit
     */
    private static function guidanceLine(?string $guidance, \Closure $bytesFit): string
    {
        if (null === $guidance) {
            return '';
        }

        $framing = \strlen(self::QUESTION) + \strlen(self::GUIDANCE_LABEL) + \strlen(self::PROFILE_LABEL);

        return self::GUIDANCE_LABEL . FittingPrefix::of(
            mb_scrub($guidance, 'UTF-8'),
            static fn (string $prefix): bool => $bytesFit($framing + \strlen($prefix)),
        );
    }
}
