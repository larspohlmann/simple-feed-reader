<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging\Loki;

use App\Service\Logging\Loki\SpoolLokiSink;
use PHPUnit\Framework\TestCase;

final class SpoolLokiSinkTest extends TestCase
{
    private string $spoolDirectory;

    protected function setUp(): void
    {
        $this->spoolDirectory = sys_get_temp_dir() . '/loki-spool-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->spoolDirectory . '/*') ?: []);
        if (is_dir($this->spoolDirectory)) {
            rmdir($this->spoolDirectory);
        }
    }

    public function testWritesOneFilePerFlushDecodingToTheInputLines(): void
    {
        $sink = new SpoolLokiSink($this->spoolDirectory);
        $lines = [[
            'ts' => '1700000000000000000',
            'line' => '{"message":"hello"}',
            'labels' => ['app' => 'sfr', 'env' => 'prod'],
        ]];

        $sink->write($lines);
        $sink->write($lines);

        $files = glob($this->spoolDirectory . '/*.json') ?: [];
        self::assertCount(2, $files);
        $decoded = json_decode((string) file_get_contents($files[0]), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($lines, $decoded);
    }

    public function testEmptyLinesWriteNothing(): void
    {
        $sink = new SpoolLokiSink($this->spoolDirectory);

        $sink->write([]);

        self::assertSame([], glob($this->spoolDirectory . '/*.json') ?: []);
    }

    public function testSwallowsWriteErrors(): void
    {
        $file = sys_get_temp_dir() . '/loki-spool-not-a-dir-' . bin2hex(random_bytes(4));
        file_put_contents($file, 'x');
        $sink = new SpoolLokiSink($file);

        $sink->write([[
            'ts' => '1',
            'line' => '{}',
            'labels' => ['app' => 'sfr'],
        ]]);

        self::assertFileExists($file);
        unlink($file);
    }

    public function testSwallowsJsonEncodingErrors(): void
    {
        $sink = new SpoolLokiSink($this->spoolDirectory);

        $sink->write([[
            'ts' => '1',
            'line' => "\xB1\x31",
            'labels' => ['app' => 'sfr'],
        ]]);

        self::assertSame([], glob($this->spoolDirectory . '/*.json') ?: []);
    }

    public function testFileNameEncodesAMicrosecondTimestampAndTwelveHexCharacters(): void
    {
        $sink = new SpoolLokiSink($this->spoolDirectory);

        $sink->write([['ts' => '1', 'line' => '{}', 'labels' => ['app' => 'sfr']]]);

        $files = glob($this->spoolDirectory . '/*.json') ?: [];
        self::assertCount(1, $files);
        self::assertMatchesRegularExpression('/^\d+-[0-9a-f]{12}\.json$/', basename($files[0]));
        [$micros] = explode('-', basename($files[0], '.json'), 2);
        self::assertGreaterThan(1_000_000_000_000, (int) $micros);
    }
}
