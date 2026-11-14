<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Model\SearchExpression;

use App\Service\Social\Social\Model\SearchedPostModel;

/** Words in order, the first starting a word of the text; the last may run on, so `reader` finds "readers". */
final readonly class SearchTermModel implements SearchExpressionModelInterface
{
    private string $pattern;

    /** @param non-empty-list<string> $lowerCasedWords */
    public function __construct(array $lowerCasedWords)
    {
        $this->pattern = '/(?<![\p{L}\p{N}])' . implode('[^\p{L}\p{N}]+', array_map(
            static fn (string $word): string => preg_quote($word, '/'),
            $lowerCasedWords,
        )) . '/u';
    }

    public function matches(SearchedPostModel $post): bool
    {
        return array_any($post->lowerCasedTexts, fn (string $text): bool => 1 === preg_match($this->pattern, $text));
    }

    public function isFullyChecked(): bool
    {
        return true;
    }
}
