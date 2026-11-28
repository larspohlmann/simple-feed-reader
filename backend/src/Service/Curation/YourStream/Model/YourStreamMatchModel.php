<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream\Model;

final readonly class YourStreamMatchModel
{
    public const int STEMS_CHARACTERS = 255;

    public function __construct(public int $userId, public int $entryId, public StreamScoreModel $score)
    {
    }

    public function formatMatchedStems(): string
    {
        return mb_substr(implode(' ', $this->score->matchedStems), 0, self::STEMS_CHARACTERS);
    }
}
