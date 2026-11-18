<?php

declare(strict_types=1);

namespace App\Enum;

enum StoryAiTask: string
{
    case Title = 'title';

    public function phase(): CallPhase
    {
        return match ($this) {
            self::Title => CallPhase::StoryTitle,
        };
    }
}
