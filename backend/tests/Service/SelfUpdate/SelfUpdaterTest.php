<?php

declare(strict_types=1);

namespace App\Tests\Service\SelfUpdate;

use App\Service\SelfUpdate\Exception\NoUpdateAvailableException;
use App\Service\SelfUpdate\Exception\UpdateInProgressException;
use App\Service\SelfUpdate\Exception\UpdaterUnavailableException;
use App\Service\SelfUpdate\Model\SelfUpdatePhase;
use App\Service\SelfUpdate\Model\UpdateRunModel;
use App\Service\SelfUpdate\SelfUpdater;
use App\Service\Version\Model\LatestReleaseModel;
use App\Service\Version\Model\ReleaseVersionModel;
use App\Tests\Support\InMemoryUpdateStateStore;
use App\Tests\Support\VersionReporters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class SelfUpdaterTest extends TestCase
{
    private const string NOW = '2026-10-31 10:00:00';

    private InMemoryUpdateStateStore $state;

    protected function setUp(): void
    {
        $this->state = new InMemoryUpdateStateStore();
    }

    public function testWithoutAHeartbeatNoUpdaterIsPresent(): void
    {
        self::assertFalse($this->updater()->status()->updaterPresent);
    }

    public function testAHeartbeatEightyNineSecondsOldMeansAnUpdaterIsPresent(): void
    {
        $this->state->heartbeat = $this->secondsAgo(89);

        self::assertTrue($this->updater()->status()->updaterPresent);
    }

    public function testAHeartbeatNinetyOneSecondsOldMeansTheUpdaterIsGone(): void
    {
        $this->state->heartbeat = $this->secondsAgo(91);

        self::assertFalse($this->updater()->status()->updaterPresent);
    }

    public function testWithoutARequestOrARunTheUpdaterIsIdle(): void
    {
        self::assertSame(SelfUpdatePhase::Idle, $this->updater()->status()->phase);
    }

    public function testARunOfTheLatestRequestReportsItsOwnPhase(): void
    {
        $this->state->pendingRequestId = 'abc';
        $this->state->lastRun = $this->runOf('abc', SelfUpdatePhase::Succeeded);

        $status = $this->updater()->status();

        self::assertSame(SelfUpdatePhase::Succeeded, $status->phase);
        self::assertSame($this->state->lastRun, $status->lastRun);
    }

    public function testARunWithoutARequestFileReportsItsOwnPhase(): void
    {
        $this->state->lastRun = $this->runOf('abc', SelfUpdatePhase::Failed);

        self::assertSame(SelfUpdatePhase::Failed, $this->updater()->status()->phase);
    }

    public function testARequestNoRunHasTakenUpYetIsRequested(): void
    {
        $this->state->pendingRequestId = 'def';
        $this->state->lastRun = $this->runOf('abc', SelfUpdatePhase::Succeeded);

        self::assertSame(SelfUpdatePhase::Requested, $this->updater()->status()->phase);
    }

    public function testTheStatusCarriesTheLogTail(): void
    {
        $this->state->log = "pulling images\n";

        self::assertSame("pulling images\n", $this->updater()->status()->log);
    }

    public function testARequestWithoutAnUpdaterIsRefused(): void
    {
        $this->expectException(UpdaterUnavailableException::class);

        $this->updater()->request('boss@example.com');
    }

    public function testARequestWhileARequestIsPendingIsRefused(): void
    {
        $this->state->heartbeat = $this->secondsAgo(10);
        $this->state->pendingRequestId = 'def';

        $this->expectException(UpdateInProgressException::class);

        $this->updater()->request('boss@example.com');
    }

    public function testARequestWhileARunIsRunningIsRefused(): void
    {
        $this->state->heartbeat = $this->secondsAgo(10);
        $this->state->pendingRequestId = 'abc';
        $this->state->lastRun = $this->runOf('abc', SelfUpdatePhase::Running);

        $this->expectException(UpdateInProgressException::class);

        $this->updater()->request('boss@example.com');
    }

    public function testARequestWithoutANewerReleaseIsRefused(): void
    {
        $this->state->heartbeat = $this->secondsAgo(10);

        $this->expectException(NoUpdateAvailableException::class);

        $this->updater(new LatestReleaseModel('v1.0.0', 'https://github.test/releases/tag/v1.0.0'))
            ->request('boss@example.com');
    }

    #[DataProvider('finishedPhases')]
    public function testARequestAfterAFinishedRunIsSavedForTheAdminAtNow(SelfUpdatePhase $finished): void
    {
        $this->state->heartbeat = $this->secondsAgo(10);
        $this->state->pendingRequestId = 'abc';
        $this->state->lastRun = $this->runOf('abc', $finished);

        $this->updater()->request('boss@example.com');

        self::assertCount(1, $this->state->savedRequests);
        $request = $this->state->savedRequests[0];
        self::assertSame('boss@example.com', $request->requestedBy);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $request->requestedAt);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $request->id);
    }

    /** @return iterable<string, array{SelfUpdatePhase}> */
    public static function finishedPhases(): iterable
    {
        yield 'succeeded' => [SelfUpdatePhase::Succeeded];
        yield 'failed' => [SelfUpdatePhase::Failed];
    }

    private function updater(
        LatestReleaseModel $latest = new LatestReleaseModel('v1.1.0', 'https://github.test/releases/tag/v1.1.0'),
    ): SelfUpdater {
        return new SelfUpdater(
            $this->state,
            VersionReporters::reporting(new ReleaseVersionModel('v1.0.0', 'abc123', '2026-01-01T00:00:00Z'), $latest),
            new MockClock(self::NOW),
        );
    }

    private function secondsAgo(int $seconds): \DateTimeImmutable
    {
        return (new \DateTimeImmutable(self::NOW))->modify(sprintf('-%d seconds', $seconds));
    }

    private function runOf(string $requestId, SelfUpdatePhase $phase): UpdateRunModel
    {
        return new UpdateRunModel($requestId, $phase, new \DateTimeImmutable(self::NOW), null, null);
    }
}
