<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Run\Support;

use App\Service\Curation\Recommendation\Pool\Model\ArticleLineModel;

/** Keeps one winner per shared article: the outlet's own article when it won, else the best-ranked post. */
final class OneWinnerPerArticle
{
    /**
     * @param list<array{id: int, score: int, reason: string}> $ranked
     * @param array<int, ArticleLineModel> $linesById
     *
     * @return list<array{id: int, score: int, reason: string}>
     */
    public static function filterWinners(array $ranked, array $linesById): array
    {
        $chosenByKey = self::chosenByKey($ranked, $linesById);

        return array_values(array_filter($ranked, static function (array $winner) use ($linesById, $chosenByKey): bool {
            $key = ($linesById[$winner['id']] ?? null)?->articleKey();

            return $key === null || $chosenByKey[$key] === $winner['id'];
        }));
    }

    /**
     * @param list<array{id: int, score: int, reason: string}> $ranked
     * @param array<int, ArticleLineModel> $linesById
     *
     * @return array<string, int> each article's chosen winner id
     */
    private static function chosenByKey(array $ranked, array $linesById): array
    {
        $firstByKey = [];
        $firstOutletByKey = [];
        foreach ($ranked as $winner) {
            $line = $linesById[$winner['id']] ?? null;
            $key = $line?->articleKey();
            if ($key === null) {
                continue;
            }
            $firstByKey[$key] ??= $winner['id'];
            if ($line->isAnOutletArticle()) {
                $firstOutletByKey[$key] ??= $winner['id'];
            }
        }

        return $firstOutletByKey + $firstByKey;
    }

    private function __construct()
    {
    }
}
