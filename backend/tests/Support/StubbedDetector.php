<?php

declare(strict_types=1);

namespace App\Tests\Support;

use LanguageDetection\Language;
use LanguageDetection\LanguageResult;

final class StubbedDetector extends Language
{
    /** @var list<string> */
    private array $seenTexts = [];

    /** @param array<string, float> $scores */
    private function __construct(private readonly array $scores)
    {
    }

    public static function saying(string $languageCode): self
    {
        return new self([$languageCode => 0.9]);
    }

    public static function sayingNothing(): self
    {
        return new self([]);
    }

    public function detect(string $str): LanguageResult
    {
        $this->seenTexts[] = $str;

        return new LanguageResult($this->scores);
    }

    /** @return list<string> */
    public function seenTexts(): array
    {
        return $this->seenTexts;
    }
}
