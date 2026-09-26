<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\RefreshFeedsCommand;
use App\Service\Refresh\RefreshReport;
use App\Service\Refresh\RefreshRequest;
use App\Tests\Service\Refresh\FakeRefreshRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class RefreshFeedsCommandRequestTest extends TestCase
{
    public function testAPlainRunPrunesAndRunsOnSchedule(): void
    {
        $request = $this->requestFor(['--budget' => '45']);

        self::assertSame(45, $request->budgetSeconds);
        self::assertTrue($request->prune);
        self::assertFalse($request->force);
    }

    public function testNoPruneTurnsOnlyThePruningOff(): void
    {
        $request = $this->requestFor(['--budget' => '45', '--no-prune' => true]);

        self::assertFalse($request->prune);
        self::assertFalse($request->force);
    }

    public function testForceTurnsOnlyTheScheduleOff(): void
    {
        $request = $this->requestFor(['--budget' => '45', '--force' => true]);

        self::assertTrue($request->force);
        self::assertTrue($request->prune);
    }

    public function testAFeedOptionRefreshesThatFeedOnly(): void
    {
        $request = $this->requestFor(['--budget' => '45', '--feed' => '7']);

        self::assertSame(7, $request->feedId);
        self::assertNull($request->userId);
    }

    public function testAUserOptionRefreshesThatUsersFeeds(): void
    {
        $request = $this->requestFor(['--budget' => '45', '--user' => '3']);

        self::assertSame(3, $request->userId);
        self::assertNull($request->feedId);
    }

    /** @param array<string, string|bool> $input */
    private function requestFor(array $input): RefreshRequest
    {
        $runner = new FakeRefreshRunner(RefreshReport::busy());
        (new CommandTester(new RefreshFeedsCommand($runner)))->execute($input);

        return $runner->requests[0] ?? self::fail('The command never asked for a refresh.');
    }
}
