<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Service\Curation\Recommendation\Llm\Prompt\ModelReplyJsonDecoder;
use App\Service\Curation\Trending\Model\StoryHeadingModel;

/** Reads the `{title, synopsis}` reply of the story-title prompt; null for a reply that gives no usable title. */
final readonly class StoryTitleParser
{
    private const int MAXIMUM_LENGTH = 120;

    private const int MAXIMUM_SYNOPSIS_LENGTH = 500;

    private const string TRAILING_PUNCTUATION = '/[\s.,;:!?…]+$/u';

    private const array WRAPPING_QUOTES = ['"' => '"', "'" => "'", '„' => '“', '“' => '”', '«' => '»', '»' => '«'];

    public function __construct(private ModelReplyJsonDecoder $decoder)
    {
    }

    public function parse(string $content): ?StoryHeadingModel
    {
        $reply = $this->decoder->decode($content);
        $title = $this->titleOf($reply['title'] ?? null);

        return $title === null ? null : new StoryHeadingModel($title, $this->synopsisOf($reply['synopsis'] ?? null));
    }

    private function titleOf(mixed $title): ?string
    {
        if (!\is_string($title)) {
            return null;
        }

        $cleaned = $this->withoutTrailingPunctuation(
            $this->withoutWrappingQuotes($this->withoutTrailingPunctuation(trim($title))),
        );

        return $cleaned === '' || mb_strlen($cleaned) > self::MAXIMUM_LENGTH ? null : $cleaned;
    }

    private function synopsisOf(mixed $synopsis): ?string
    {
        if (!\is_string($synopsis)) {
            return null;
        }

        $collapsed = trim(preg_replace('/\s+/u', ' ', $synopsis) ?? $synopsis);

        return $collapsed === '' || mb_strlen($collapsed) > self::MAXIMUM_SYNOPSIS_LENGTH ? null : $collapsed;
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
