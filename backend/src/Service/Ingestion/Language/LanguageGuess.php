<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Language;

use App\Enum\FeedLanguage;
use LanguageDetection\Language;

/** The detector's top language with no confidence floor: a wrong guess costs little where it is used (#2227). */
final readonly class LanguageGuess
{
    /** A stated language stands below this length: a short text is where the guesses go wrong (#2227). */
    private const int LONG_TEXT_LENGTH = 150;

    public function __construct(
        private Language $detection,
    ) {
    }

    public function overrulesAStatedLanguage(string $text): bool
    {
        return mb_strlen($text) >= self::LONG_TEXT_LENGTH;
    }

    public function bestFor(string $text): ?FeedLanguage
    {
        if (preg_match('/\p{L}/u', $text) !== 1) {
            return null;
        }

        return FeedLanguage::tryFrom((string) array_key_first($this->detection->detect($text)->close()));
    }
}
