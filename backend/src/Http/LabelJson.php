<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Label;
use App\Service\Library\Label\Model\LabelTalliesModel;
use App\Service\Library\Label\Model\LabelTallyModel;

final class LabelJson
{
    /** @return array{id: int, name: string} */
    public static function one(Label $label): array
    {
        return ['id' => $label->requireId(), 'name' => $label->getName()];
    }

    /**
     * @return array{
     *   labels: list<array{id: int, name: string, favoriteCount: int, keptCount: int, articleCount: int}>,
     *   unlabeled: array{favoriteCount: int, keptCount: int}
     * }
     */
    public static function tallies(LabelTalliesModel $tallies): array
    {
        return [
            'labels' => array_map(
                static fn (LabelTallyModel $tally): array => [
                    'id' => $tally->id,
                    'name' => $tally->name,
                    'favoriteCount' => $tally->favoriteCount,
                    'keptCount' => $tally->keptCount,
                    'articleCount' => $tally->articleCount,
                ],
                $tallies->labels,
            ),
            'unlabeled' => [
                'favoriteCount' => $tallies->unlabeledFavoriteCount,
                'keptCount' => $tallies->unlabeledKeptCount,
            ],
        ];
    }
}
