<?php

declare(strict_types=1);

namespace App\Tests\Service\SelfUpdate\UpdateStateStore;

use App\Service\SelfUpdate\Exception\MalformedUpdateStateException;
use App\Service\SelfUpdate\Exception\UpdateRequestNotSavedException;
use App\Service\SelfUpdate\Model\SelfUpdatePhase;
use App\Service\SelfUpdate\Model\UpdateRequestModel;
use App\Service\SelfUpdate\UpdateStateStore\FileUpdateStateStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class FileUpdateStateStoreTest extends TestCase
{
    private string $directory;

    private FileUpdateStateStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/update-state-' . uniqid();
        $this->store = new FileUpdateStateStore($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testAnAbsentDirectoryHoldsNoState(): void
    {
        self::assertNull($this->store->heartbeatAt());
        self::assertNull($this->store->pendingRequestId());
        self::assertNull($this->store->lastRun());
        self::assertSame('', $this->store->logTail());
    }

    public function testReadsTheHeartbeatAsAUtcInstant(): void
    {
        $this->write('heartbeat', "1793440800\n");

        $heartbeat = $this->store->heartbeatAt();

        self::assertNotNull($heartbeat);
        self::assertSame('2026-10-31T10:00:00+00:00', $heartbeat->format(\DATE_ATOM));
    }

    public function testAnEmptyHeartbeatIsNoHeartbeat(): void
    {
        $this->write('heartbeat', '');

        self::assertNull($this->store->heartbeatAt());
    }

    public function testANonNumericHeartbeatIsNoHeartbeat(): void
    {
        $this->write('heartbeat', 'abc');

        self::assertNull($this->store->heartbeatAt());
    }

    public function testReadsTheLastRunAndIgnoresUnknownFields(): void
    {
        $this->write('status.json', json_encode([
            'requestId' => 'abc',
            'phase' => 'failed',
            'startedAt' => '2026-10-31T11:00:00+01:00',
            'finishedAt' => '2026-10-31T10:05:00Z',
            'exitCode' => 2,
            'addedLater' => 'ignored',
        ], \JSON_THROW_ON_ERROR));

        $run = $this->store->lastRun();

        self::assertNotNull($run);
        self::assertSame('abc', $run->requestId);
        self::assertSame(SelfUpdatePhase::Failed, $run->phase);
        self::assertSame('2026-10-31T10:00:00+00:00', $run->startedAt?->format(\DATE_ATOM));
        self::assertSame('2026-10-31T10:05:00+00:00', $run->finishedAt?->format(\DATE_ATOM));
        self::assertSame(2, $run->exitCode);
    }

    public function testARunningRunHasNoFinishOrExitCodeYet(): void
    {
        $this->write('status.json', json_encode([
            'requestId' => 'abc',
            'phase' => 'running',
            'startedAt' => '2026-10-31T10:00:00Z',
            'finishedAt' => null,
            'exitCode' => null,
        ], \JSON_THROW_ON_ERROR));

        $run = $this->store->lastRun();

        self::assertNotNull($run);
        self::assertSame(SelfUpdatePhase::Running, $run->phase);
        self::assertNull($run->finishedAt);
        self::assertNull($run->exitCode);
    }

    public function testAnEmptyStartTimeIsNoStartTime(): void
    {
        $this->write('status.json', '{"requestId":"abc","phase":"failed","startedAt":"","finishedAt":""}');

        $run = $this->store->lastRun();

        self::assertNotNull($run);
        self::assertNull($run->startedAt);
        self::assertNull($run->finishedAt);
    }

    #[DataProvider('malformedStatusFiles')]
    public function testAStructurallyWrongStatusFileIsMalformed(string $contents): void
    {
        $this->write('status.json', $contents);

        $this->expectException(MalformedUpdateStateException::class);

        $this->store->lastRun();
    }

    /** @return iterable<string, array{string}> */
    public static function malformedStatusFiles(): iterable
    {
        yield 'a list' => ['[]'];
        yield 'not JSON' => ['{"requestId":'];
        yield 'no request id' => ['{"phase":"running"}'];
        yield 'an unknown phase' => ['{"requestId":"abc","phase":"sleeping"}'];
        yield 'an unreadable date' => ['{"requestId":"abc","phase":"running","startedAt":"soon"}'];
    }

    public function testTheLogTailIsTheLastSixteenKibibytes(): void
    {
        $log = str_repeat('a', 24 * 1024) . str_repeat('b', 16 * 1024);
        $this->write('run.log', $log);

        self::assertSame(str_repeat('b', 16 * 1024), $this->store->logTail());
    }

    public function testAShortLogIsReadWhole(): void
    {
        $this->write('run.log', "pulling images\n");

        self::assertSame("pulling images\n", $this->store->logTail());
    }

    public function testASavedRequestReadsBackAsPendingWithoutATempFileLeft(): void
    {
        mkdir($this->directory);

        $this->store->saveRequest($this->request());

        self::assertSame('0123456789abcdef', $this->store->pendingRequestId());
        self::assertFileDoesNotExist($this->directory . '/request.json.tmp');
        self::assertSame(
            [
                'id' => '0123456789abcdef',
                'requestedBy' => 'boss@example.com',
                'requestedAt' => '2026-10-31T10:00:00+00:00',
            ],
            json_decode((string) file_get_contents($this->directory . '/request.json'), true),
        );
    }

    public function testSavingIntoAMissingDirectoryFails(): void
    {
        $this->expectException(UpdateRequestNotSavedException::class);

        $this->store->saveRequest($this->request());
    }

    public function testSavingIntoAReadOnlyDirectoryFails(): void
    {
        mkdir($this->directory, 0o555);
        if (is_writable($this->directory)) {
            self::markTestSkipped('root writes into a read-only directory.');
        }

        try {
            $this->store->saveRequest($this->request());
            self::fail('A read-only directory took the request.');
        } catch (UpdateRequestNotSavedException) {
            self::assertFileDoesNotExist($this->directory . '/request.json');
        } finally {
            chmod($this->directory, 0o755);
        }
    }

    private function request(): UpdateRequestModel
    {
        return new UpdateRequestModel(
            '0123456789abcdef',
            'boss@example.com',
            new \DateTimeImmutable('2026-10-31 10:00:00'),
        );
    }

    private function write(string $name, string $contents): void
    {
        (new Filesystem())->dumpFile($this->directory . '/' . $name, $contents);
    }
}
