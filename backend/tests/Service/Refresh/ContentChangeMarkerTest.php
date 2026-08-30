<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Service\Refresh\ContentChangeMarker;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ContentChangeMarkerTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/content-change-marker-' . bin2hex(random_bytes(4));
        mkdir($this->projectDir . '/public', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeRecursively($this->projectDir);
    }

    public function testWritesANonEmptyTokenIntoTheWebRoot(): void
    {
        $this->marker()->markChanged();

        self::assertFileExists($this->markerPath());
        self::assertNotSame('', trim((string) file_get_contents($this->markerPath())));
    }

    public function testEachCallWritesADifferentToken(): void
    {
        $marker = $this->marker();

        $marker->markChanged();
        $first = file_get_contents($this->markerPath());
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

    public function testAnUnwritableWebRootDoesNotThrow(): void
    {
        $file = $this->projectDir . '/a-file-not-a-tree';
        file_put_contents($file, 'x');

        (new ContentChangeMarker($file, new NullLogger()))->markChanged();

        $this->expectNotToPerformAssertions();
    }

    private function marker(): ContentChangeMarker
    {
        return new ContentChangeMarker($this->projectDir, new NullLogger());
    }

    private function markerPath(): string
    {
        return $this->projectDir . '/public/state/counts';
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
