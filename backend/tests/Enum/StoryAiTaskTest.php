<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\CallPhase;
use App\Enum\StoryAiTask;
use PHPUnit\Framework\TestCase;

final class StoryAiTaskTest extends TestCase
{
    public function testTheTitleTaskLogsItsCallsUnderTheStoryTitlePhase(): void
    {
        self::assertSame(CallPhase::StoryTitle, StoryAiTask::Title->phase());
    }

    /** The stored `story_ai_run.task` strings: changing one needs a data migration. */
    public function testTheStoredValuesAreStable(): void
    {
        self::assertSame(
            ['title'],
            array_map(static fn (StoryAiTask $task): string => $task->value, StoryAiTask::cases()),
        );
    }
}
