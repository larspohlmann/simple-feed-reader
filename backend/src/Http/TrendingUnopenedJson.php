<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Curation\Trending\Model\TrendingUnopenedModel;

final class TrendingUnopenedJson
{
    /** @return array{window: string, unopened: int} */
    public static function from(TrendingUnopenedModel $unopened): array
    {
        return ['window' => $unopened->window->value, 'unopened' => $unopened->unopened];
    }
}
