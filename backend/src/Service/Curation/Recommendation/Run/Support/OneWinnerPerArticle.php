<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Run\Support;

use App\Service\Curation\Recommendation\Pool\Model\ArticleLineModel;

/** Keeps one winner per shared article: the outlet's own article when it won, else the best-ranked post. */
final readonly class OneWinnerPerArticle
{
    /**
     * @param list<array{id: int, score: int, reason: string}> $ranked
     * @param array<int, ArticleLineModel> $linesById
     *
     * @return list<array{id: int, score: int, reason: string}>
     */
    public static function of(array $ranked, array $linesById): array
    {
        $outletWinnerByKey = [];
        foreach ($ranked as $winner) {
            $line = $linesById[$winner['id']] ?? null;
            $key = $line?->articleKey();
            if ($key !== null && $line->isAnOutletArticle()) {
                $outletWinnerByKey[$key] ??= $winner['id'];
            }
        }

        $seenKeys = [];
        $kept = [];
        foreach ($ranked as $winner) {
            $key = ($linesById[$winner['id']] ?? null)?->articleKey();
            if ($key === null) {
                $kept[] = $winner;
                continue;
            }
            $stays = \array_key_exists($key, $outletWinnerByKey)
                ? $outletWinnerByKey[$key] === $winner['id']
                : !isset($seenKeys[$key]);
            $seenKeys[$key] = true;
            if ($stays) {
                $kept[] = $winner;
            }
        }

        return $kept;
    }

    private function __construct()
    {
    }
}
