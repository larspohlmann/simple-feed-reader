<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Service\Refresh\ContentChangeMarker;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

final class ContentChangeMarkerTest extends TestCase
{
    private string $projectDir;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/content-change-marker-' . bin2hex(random_bytes(4));
        mkdir($this->projectDir . '/public', 0775, true);
        // Not UTC: the marker must convert, because the worker clock is Berlin
        // time on Strato and the timestamp it writes has to read as UTC anyway.
        $this->clock = new MockClock('2026-08-30 16:05:12.845123', 'Europe/Berlin');
    }

    protected function tearDown(): void
    {
        $this->removeRecursively($this->projectDir);
    }

    public function testWritesTheLastImportTimeAsUtcJson(): void
    {
        $this->marker()->markChanged();

        $document = json_decode((string) file_get_contents($this->markerPath()), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        // 16:05 Berlin is 14:05 UTC, written with a trailing Z.
        self::assertSame('2026-08-30T14:05:12.845123Z', $document['lastUpdated']);
    }

    public function testEachImportWritesALaterTimestamp(): void
    {
        $marker = $this->marker();

        $marker->markChanged();
        $first = file_get_contents($this->markerPath());
        $this->clock->sleep(1);
        $marker->markChanged();
        $second = file_get_contents($this->markerPath());

        self::assertNotSame($first, $second);
    }

    public function testCreatesTheStateDirectoryWhenMissing(): void
    {
        self::assertDirectoryDoesNotExist($this->projectDir . '/public/state');

        $this->marker()->markChanged();

        self::assertDirectoryExists($this->projectDir . '/public/state');
    }

    public function testTheMarkerIsWorldReadable(): void
    {
        $this->marker()->markChanged();

        self::assertSame('0644', substr(sprintf('%o', fileperms($this->markerPath())), -4));
    }

    public function testLeavesNoTempFileBesideTheMarker(): void
    {
        $this->marker()->markChanged();

        $entries = array_diff((array) scandir($this->projectDir . '/public/state'), ['.', '..']);
        self::assertSame(['counts.json'], array_values($entries));
    }

    public function testAWrittenMarkerLogsNothing(): void
    {
        $logger = new RecordingLogger();

        (new ContentChangeMarker($this->projectDir, $this->clock, $logger))->markChanged();

        self::assertSame([], $logger->records);
    }

    public function testAnUnwritableWebRootLogsExactlyOneWarningNamingTheDirectory(): void
    {
        $file = $this->projectDir . '/a-file-not-a-tree';
        file_put_contents($file, 'x');
        $logger = new RecordingLogger();

        (new ContentChangeMarker($file, $this->clock, $logger))->markChanged();

        self::assertCount(1, $logger->records);
        self::assertStringContainsString('{directory}', $logger->records[0]['message']);
        self::assertSame($file . '/public/state', $logger->records[0]['context']['directory']);
        self::assertFileDoesNotExist($file . '/public/state/counts.json');
    }

    public function testAnUnwritableWebRootDoesNotThrow(): void
    {
        $file = $this->projectDir . '/a-file-not-a-tree';
        file_put_contents($file, 'x');

        (new ContentChangeMarker($file, $this->clock, new NullLogger()))->markChanged();

        $this->expectNotToPerformAssertions();
    }

    private function marker(): ContentChangeMarker
    {
        return new ContentChangeMarker($this->projectDir, $this->clock, new NullLogger());
    }

    private function markerPath(): string
    {
        return $this->projectDir . '/public/state/counts.json';
    }

    private function removeRecursively(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path)) {
            unlink($path);

            return;
        }
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $this->removeRecursively($path . '/' . $entry);
        }
        rmdir($path);
    }
}
