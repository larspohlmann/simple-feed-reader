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

    public function readsReliably(string $text): bool
    {
        return mb_strlen($text) >= self::LONG_TEXT_LENGTH;
    }

    /** The guess where it may overrule a stated language, on a long text; null on a short one. */
    public function detectConfidentLanguage(string $text): ?FeedLanguage
    {
        return $this->readsReliably($text) ? $this->detectLanguage($text) : null;
    }

    public function detectLanguage(string $text): ?FeedLanguage
    {
        if (preg_match('/\p{L}/u', $text) !== 1) {
            return null;
        }

        return FeedLanguage::tryFrom((string) array_key_first($this->detection->detect($text)->close()));
    }
}
