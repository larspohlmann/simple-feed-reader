<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Service\Curation\Recommendation\Llm\Prompt\ModelReplyJsonDecoder;
use App\Service\Curation\Trending\Model\StoryHeadingModel;
use App\Service\Foundation\Text\Support\Whitespace;

/** Reads the `{title, synopsis}` reply of the story-title prompt; null for a reply that gives no usable title. */
final readonly class StoryTitleParser
{
    private const int MAXIMUM_LENGTH = 120;

    private const int MAXIMUM_SYNOPSIS_LENGTH = 900;

    private const string TRAILING_PUNCTUATION = '/[\s.,;:!?…]+$/u';

    private const string LAST_SENTENCE_END = '/^.*[.!?…](?=\s)/su';

    private const array WRAPPING_QUOTES = ['"' => '"', "'" => "'", '„' => '“', '“' => '”', '«' => '»', '»' => '«'];

    public function __construct(private ModelReplyJsonDecoder $decoder)
    {
    }

    public function parse(string $content): ?StoryHeadingModel
    {
        $reply = $this->decoder->decode($content);
        $title = $this->parseTitle($reply['title'] ?? null);

        return $title === null ? null : new StoryHeadingModel($title, $this->parseSynopsis($reply['synopsis'] ?? null));
    }

    private function parseTitle(mixed $title): ?string
    {
        $collapsed = $this->parseCollapsed($title);
        $cleaned = $collapsed === null ? '' : $this->withoutQuotesAndPunctuation($collapsed);

        return $cleaned === '' || mb_strlen($cleaned) > self::MAXIMUM_LENGTH ? null : $cleaned;
    }

    private function parseCollapsed(mixed $text): ?string
    {
        $collapsed = \is_string($text) ? Whitespace::collapse($text) : '';

        return $collapsed === '' ? null : $collapsed;
    }

    private function withoutQuotesAndPunctuation(string $title): string
    {
        return $this->withoutTrailingPunctuation(
            $this->withoutWrappingQuotes($this->withoutTrailingPunctuation($title)),
        );
    }

    private function parseSynopsis(mixed $synopsis): ?string
    {
        $collapsed = $this->parseCollapsed($synopsis);

        return $collapsed === null || mb_strlen($collapsed) <= self::MAXIMUM_SYNOPSIS_LENGTH
            ? $collapsed
            : $this->parseSentencesThatFit($collapsed);
    }

    private function parseSentencesThatFit(string $synopsis): ?string
    {
        $head = mb_substr($synopsis, 0, self::MAXIMUM_SYNOPSIS_LENGTH + 1);

        return preg_match(self::LAST_SENTENCE_END, $head, $match) === 1 ? $match[0] : null;
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
