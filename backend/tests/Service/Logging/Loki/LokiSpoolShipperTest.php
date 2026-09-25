<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging\Loki;

use App\Service\Logging\Loki\LokiClient;
use App\Service\Logging\Loki\LokiSpoolShipper;
use App\Tests\Support\StubLokiEndpoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LokiSpoolShipperTest extends TestCase
{
    private string $spoolDirectory;

    protected function setUp(): void
    {
        $this->spoolDirectory = sys_get_temp_dir() . '/loki-ship-test-' . bin2hex(random_bytes(4));
        mkdir($this->spoolDirectory, 0770, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->spoolDirectory . '/*') ?: []);
        rmdir($this->spoolDirectory);
    }

    public function testShipsEveryFileThenDeletesItAndPushesEachBatch(): void
    {
        $this->spool([['ts' => '1', 'line' => '{"m":"a"}', 'labels' => ['app' => 'sfr']]]);
        $this->spool([['ts' => '2', 'line' => '{"m":"b"}', 'labels' => ['app' => 'sfr']]]);
        $pushes = 0;
        $http = new MockHttpClient(function () use (&$pushes): MockResponse {
            ++$pushes;

            return new MockResponse('', ['http_code' => 204]);
        });
        $shipper = new LokiSpoolShipper(new LokiClient($http, new StubLokiEndpoint()), $this->spoolDirectory);

        $report = $shipper->ship();

        self::assertSame(2, $report->shipped);
        self::assertSame(0, $report->failed);
        self::assertSame(2, $pushes);
        self::assertSame([], glob($this->spoolDirectory . '/*.json') ?: []);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function corruptSpoolContents(): iterable
    {
        yield 'not valid JSON' => ['not json'];
        yield 'a JSON scalar, not a batch' => ['42'];
    }

    #[DataProvider('corruptSpoolContents')]
    public function testDeletesACorruptFileAndCountsItFailed(string $contents): void
    {
        file_put_contents($this->spoolDirectory . '/1-deadbeef.json', $contents);
        $shipper = new LokiSpoolShipper(
            new LokiClient(new MockHttpClient(), new StubLokiEndpoint()),
            $this->spoolDirectory,
        );

        $report = $shipper->ship();

        self::assertSame(0, $report->shipped);
        self::assertSame(1, $report->failed);
        self::assertSame([], glob($this->spoolDirectory . '/*.json') ?: []);
    }

    /**
     * A dangling symlink is exactly what a concurrent tick racing unlink()
     * against glob() can leave behind: glob() lists it, but
     * file_get_contents() on a target that no longer exists returns false
     * rather than throwing. That must count as a failure, not a fatal error,
     * and the link must not be left to trip the next tick.
     */
    public function testCountsAnUnreadableFileAsFailedAndDeletesIt(): void
    {
        $link = $this->spoolDirectory . '/2-dangling.json';
        symlink('/nonexistent-loki-spool-target', $link);
        $shipper = new LokiSpoolShipper(
            new LokiClient(new MockHttpClient(), new StubLokiEndpoint()),
            $this->spoolDirectory,
        );

        $report = $shipper->ship();

        self::assertSame(0, $report->shipped);
        self::assertSame(1, $report->failed);
        clearstatcache(true, $link);
        self::assertFalse(is_link($link), 'the dangling symlink was left behind');
    }

    public function testShipsAtMostOneHundredFilesPerCallOldestFirst(): void
    {
        for ($i = 0; $i < 101; ++$i) {
            $this->spool([['ts' => (string) $i, 'line' => '{"m":"a"}', 'labels' => ['app' => 'sfr']]]);
        }
        $http = new MockHttpClient(fn (): MockResponse => new MockResponse('', ['http_code' => 204]));
        $shipper = new LokiSpoolShipper(new LokiClient($http, new StubLokiEndpoint()), $this->spoolDirectory);

        $firstReport = $shipper->ship();

        self::assertSame(100, $firstReport->shipped);
        self::assertCount(1, glob($this->spoolDirectory . '/*.json') ?: []);

        $secondReport = $shipper->ship();

        self::assertSame(1, $secondReport->shipped);
        self::assertSame([], glob($this->spoolDirectory . '/*.json') ?: []);
    }

    /**
     * A batch of several lines in one spool file must ship every line, not
     * just the first: linesIn() decodes the whole file and hands it to
     * push() unchanged.
     */
    public function testShipsEveryLineOfAMultiLineBatchNotJustTheFirst(): void
    {
        $this->spool([
            ['ts' => '1', 'line' => '{"m":"a"}', 'labels' => ['app' => 'sfr']],
            ['ts' => '2', 'line' => '{"m":"b"}', 'labels' => ['app' => 'sfr']],
            ['ts' => '3', 'line' => '{"m":"c"}', 'labels' => ['app' => 'sfr']],
        ]);
        $capturedBody = null;
        $http = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$capturedBody): MockResponse {
                $capturedBody = $options['body'] ?? null;

                return new MockResponse('', ['http_code' => 204]);
            },
        );
        $shipper = new LokiSpoolShipper(new LokiClient($http, new StubLokiEndpoint()), $this->spoolDirectory);

        $report = $shipper->ship();

        self::assertSame(1, $report->shipped);
        self::assertIsString($capturedBody);
        $payload = json_decode($capturedBody, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsArray($payload['streams']);
        self::assertIsArray($payload['streams'][0]);
        self::assertIsArray($payload['streams'][0]['values']);
        self::assertCount(3, $payload['streams'][0]['values']);
    }

    public function testEmptyDirectoryIsANoOp(): void
    {
        $shipper = new LokiSpoolShipper(
            new LokiClient(new MockHttpClient(), new StubLokiEndpoint()),
            $this->spoolDirectory,
        );

        $report = $shipper->ship();

        self::assertSame(0, $report->shipped);
        self::assertSame(0, $report->failed);
    }

    public function testShipsAFileNestedToTheDefaultJsonDepthLimit(): void
    {
        $this->spoolRawJson($this->nestedArrayJson(511));
        $client = new LokiClient(new MockHttpClient(), new StubLokiEndpoint(pushUrl: null));
        $shipper = new LokiSpoolShipper($client, $this->spoolDirectory);

        $report = $shipper->ship();

        self::assertSame(1, $report->shipped);
        self::assertSame(0, $report->failed);
    }

    public function testDeletesAFileNestedOneLevelBeyondTheDefaultJsonDepthLimitAndCountsItFailed(): void
    {
        $this->spoolRawJson($this->nestedArrayJson(512));
        $client = new LokiClient(new MockHttpClient(), new StubLokiEndpoint(pushUrl: null));
        $shipper = new LokiSpoolShipper($client, $this->spoolDirectory);

        $report = $shipper->ship();

        self::assertSame(0, $report->shipped);
        self::assertSame(1, $report->failed);
    }

    private function nestedArrayJson(int $depth): string
    {
        return str_repeat('[', $depth) . '1' . str_repeat(']', $depth);
    }

    private function spoolRawJson(string $json): void
    {
        $fileName = sprintf(
            '%s/%d-%s.json',
            $this->spoolDirectory,
            (int) (microtime(true) * 1_000_000),
            bin2hex(random_bytes(6)),
        );
        file_put_contents($fileName, $json);
    }

    /**
     * @param list<array{ts: string, line: string, labels: array<string, string>}> $lines
     */
    private function spool(array $lines): void
    {
        $this->spoolRawJson(json_encode($lines, JSON_THROW_ON_ERROR));
        usleep(1000);
    }
}
