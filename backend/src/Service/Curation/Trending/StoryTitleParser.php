<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Service\Curation\Recommendation\Llm\Prompt\ModelReplyJsonDecoder;

/** Reads the `{title}` reply of the story-title prompt; null for a reply that gives no usable title. */
final readonly class StoryTitleParser
{
    private const int MAXIMUM_LENGTH = 120;

    private const string TRAILING_PUNCTUATION = '/[\s.,;:!?…]+$/u';

    private const array WRAPPING_QUOTES = ['"' => '"', "'" => "'", '„' => '“', '“' => '”', '«' => '»', '»' => '«'];

    public function __construct(private ModelReplyJsonDecoder $decoder)
    {
    }

    public function parse(string $content): ?string
    {
        $title = $this->decoder->decode($content)['title'] ?? null;
        if (!\is_string($title)) {
            return null;
        }

        $cleaned = $this->withoutTrailingPunctuation(
            $this->withoutWrappingQuotes($this->withoutTrailingPunctuation(trim($title))),
        );

        return $cleaned === '' || mb_strlen($cleaned) > self::MAXIMUM_LENGTH ? null : $cleaned;
    }

    private function withoutWrappingQuotes(string $title): string
    {
        $opening = mb_substr($title, 0, 1);
        $closing = self::WRAPPING_QUOTES[$opening] ?? null;
        if ($closing === null || mb_strlen($title) < 2 || !str_ends_with($title, $closing)) {
            return $title;
        }

        return trim(mb_substr($title, 1, -1));
    }

    private function withoutTrailingPunctuation(string $title): string
    {
        return preg_replace(self::TRAILING_PUNCTUATION, '', $title) ?? $title;
    }
}
