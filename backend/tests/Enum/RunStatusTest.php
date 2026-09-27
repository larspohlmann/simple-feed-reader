<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\RunStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RunStatusTest extends TestCase
{
    /** The stored `recommendation_run.status` strings: changing one needs a data migration. */
    public function testTheStoredValuesAreTheOldConstants(): void
    {
        self::assertSame(
            ['pending', 'running', 'completed', 'failed', 'cancelled'],
            array_map(static fn (RunStatus $status): string => $status->value, RunStatus::cases()),
        );
    }

    #[DataProvider('activeStatuses')]
    public function testAnActiveStatusIsNotTerminal(RunStatus $status): void
    {
        self::assertTrue($status->isActive());
        self::assertFalse($status->isTerminal());
    }

    #[DataProvider('terminalStatuses')]
    public function testATerminalStatusIsNotActive(RunStatus $status): void
    {
        self::assertTrue($status->isTerminal());
        self::assertFalse($status->isActive());
    }

    /** @return iterable<string, array{RunStatus}> */
    public static function activeStatuses(): iterable
    {
        yield 'pending' => [RunStatus::Pending];
        yield 'running' => [RunStatus::Running];
    }

    /** @return iterable<string, array{RunStatus}> */
    public static function terminalStatuses(): iterable
    {
        yield 'completed' => [RunStatus::Completed];
        yield 'failed' => [RunStatus::Failed];
        yield 'cancelled' => [RunStatus::Cancelled];
    }
}
