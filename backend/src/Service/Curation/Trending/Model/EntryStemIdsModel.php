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
     * @param list<array<int, bool>> $headlines each headline text's stems: stem id => whether a word holds it, rather
     *                                          than only a compound's part
     * @param list<array<int, bool>> $bodies the same for each body text
     */
    public static function fromHeadlinesAndBodies(array $headlines, array $bodies): self
    {
        $headline = self::mergeStems($headlines);
        $body = self::mergeStems($bodies);

        return new self(
            array_keys($headline),
            array_keys(array_diff_key($body, $headline)),
            array_keys(self::mergeStems([$headline, $body]), false, true),
        );
    }

    /**
     * @param list<array<int, bool>> $texts
     *
     * @return array<int, bool>
     */
    private static function mergeStems(array $texts): array
    {
        $heldByAWord = [];
        foreach ($texts as $stems) {
            foreach ($stems as $stemId => $isAWord) {
                $heldByAWord[$stemId] = ($heldByAWord[$stemId] ?? false) || $isAWord;
            }
        }

        return $heldByAWord;
    }
}
