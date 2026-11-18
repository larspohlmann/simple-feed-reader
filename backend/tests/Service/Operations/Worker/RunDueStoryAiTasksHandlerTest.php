<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations\Worker;

use App\Repository\UserRepository;
use App\Service\Curation\StoryAi\StoryAiSweep;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Operations\Worker\Handler\RunDueStoryAiTasksHandler;
use App\Service\Operations\Worker\Message\RunDueStoryAiTasks;
use App\Tests\DbTestCase;
use App\Tests\Support\RecordingLogger;
use Symfony\Component\Clock\MockClock;

final class RunDueStoryAiTasksHandlerTest extends DbTestCase
{
    public function testFiringRunsTheSweepAndLogsTheReport(): void
    {
        $logger = new RecordingLogger();
        /** @var UserRepository $users */
        $users = self::getContainer()->get(UserRepository::class);
        $sweep = new StoryAiSweep([], $users, new NaiveUtcClock(new MockClock('2031-05-14 12:00:00', 'UTC')));

        (new RunDueStoryAiTasksHandler($sweep, $logger))(new RunDueStoryAiTasks());

        self::assertCount(1, $logger->records);
        self::assertSame('Worker story AI tasks finished.', $logger->records[0]['message']);
        self::assertSame(
            ['usable' => 0, 'unusable' => 0, 'failed' => 0],
            $logger->records[0]['context']['report'],
        );
    }
}
