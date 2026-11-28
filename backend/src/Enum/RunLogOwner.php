<?php

declare(strict_types=1);

namespace App\Enum;

/** The run association a debug log row hangs from; the value is that association's property on the log. */
enum RunLogOwner: string
{
    case Run = 'run';
    case ProfileRun = 'profileRun';
    case StoryAiRun = 'storyAiRun';
}
