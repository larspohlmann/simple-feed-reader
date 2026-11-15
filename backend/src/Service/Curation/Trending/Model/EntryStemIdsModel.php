<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

final readonly class EntryStemIdsModel
{
    /**
     * @param list<int> $headlineIds
     * @param list<int> $bodyIds the body's stems the headline lacks
     * @param list<int> $partOnlyIds the stems no word of the entry holds, only a compound's parts
     */
    private function __construct(public array $headlineIds, public array $bodyIds, public array $partOnlyIds)
    {
    }

    /**
     * @param array<int, bool> $headline stem id => whether a word holds it, rather than only a compound's part
     * @param array<int, bool> $body the same for the body
     */
    public static function of(array $headline, array $body): self
    {
        $heldByAWord = $headline;
        foreach ($body as $stemId => $isAWord) {
            $heldByAWord[$stemId] = ($heldByAWord[$stemId] ?? false) || $isAWord;
        }

        return new self(
            array_keys($headline),
            array_keys(array_diff_key($body, $headline)),
            array_keys($heldByAWord, false, true),
        );
    }
}
