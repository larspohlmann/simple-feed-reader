<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream\Model;

final readonly class StreamScoreModel
{
    /** @param list<string> $matchedStems the stems that scored, the strongest contribution first */
    public function __construct(public float $score, public array $matchedStems)
    {
    }
}
