<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging\Loki;

use App\Service\Logging\Loki\LokiClient;
use App\Service\Logging\Loki\LokiEndpoint;
use App\Service\Logging\Loki\LokiSpoolShipper;
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
        $shipper = new LokiSpoolShipper(new LokiClient($http, $this->endpoint()), $this->spoolDirectory);

        $report = $shipper->ship();

        self::assertSame(2, $report->shipped);
        self::assertSame(0, $report->failed);
        self::assertSame(2, $pushes);
        self::assertSame([], glob($this->spoolDirectory . '/*.json') ?: []);
    }

    public function testDeletesACorruptFileAndCountsItFailed(): void
    {
        file_put_contents($this->spoolDirectory . '/1-deadbeef.json', 'not json');
        $shipper = new LokiSpoolShipper(new LokiClient(new MockHttpClient(), $this->endpoint()), $this->spoolDirectory);

        $report = $shipper->ship();

        self::assertSame(0, $report->shipped);
        self::assertSame(1, $report->failed);
        self::assertSame([], glob($this->spoolDirectory . '/*.json') ?: []);
    }

    public function testEmptyDirectoryIsANoOp(): void
    {
        $shipper = new LokiSpoolShipper(new LokiClient(new MockHttpClient(), $this->endpoint()), $this->spoolDirectory);

        $report = $shipper->ship();

        self::assertSame(0, $report->shipped);
        self::assertSame(0, $report->failed);
    }

    public function testShipsAFileNestedToTheDefaultJsonDepthLimit(): void
    {
        $this->spoolRawJson($this->nestedArrayJson(511));
        $client = new LokiClient(new MockHttpClient(), $this->endpointWithNoPushUrl());
        $shipper = new LokiSpoolShipper($client, $this->spoolDirectory);

        $report = $shipper->ship();

        self::assertSame(1, $report->shipped);
        self::assertSame(0, $report->failed);
    }

    public function testDeletesAFileNestedOneLevelBeyondTheDefaultJsonDepthLimitAndCountsItFailed(): void
    {
        $this->spoolRawJson($this->nestedArrayJson(512));
        $client = new LokiClient(new MockHttpClient(), $this->endpointWithNoPushUrl());
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

    private function endpointWithNoPushUrl(): LokiEndpoint
    {
        return new class implements LokiEndpoint {
            public function pushUrl(): ?string
            {
                return null;
            }

            public function username(): ?string
            {
                return null;
            }

            public function token(): ?string
            {
                return null;
            }
        };
    }

    /**
     * @param list<array{ts: string, line: string, labels: array<string, string>}> $lines
     */
    private function spool(array $lines): void
    {
        $fileName = sprintf(
            '%s/%d-%s.json',
            $this->spoolDirectory,
            (int) (microtime(true) * 1_000_000),
            bin2hex(random_bytes(6)),
        );
        file_put_contents($fileName, json_encode($lines, JSON_THROW_ON_ERROR));
        usleep(1000);
    }

    private function endpoint(): LokiEndpoint
    {
        return new class implements LokiEndpoint {
            public function pushUrl(): string
            {
                return 'http://loki:3100/loki/api/v1/push';
            }

            public function username(): ?string
            {
                return null;
            }

            public function token(): ?string
            {
                return null;
            }
        };
    }
}
