<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Engine\Model;

/** Which engine turns a connection's runs into a list; the value keys the engine in the resolver's locator. */
enum RecommendationEngineKind: string
{
    case Llm = 'llm';
}
