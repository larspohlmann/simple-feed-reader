<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging\Loki;

use App\Service\Logging\Loki\DirectLokiSink;
use App\Service\Logging\Loki\LokiClient;
use App\Service\Logging\Loki\LokiSinkFactory;
use App\Service\Logging\Loki\SpoolLokiSink;
use App\Tests\Support\StubLokiEndpoint;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class LokiSinkFactoryTest extends TestCase
{
    public function testCliAlwaysSelectsDirectEvenWithoutFastcgiFinishRequest(): void
    {
        self::assertSame('direct', LokiSinkFactory::selects('cli', false));
        self::assertSame('direct', LokiSinkFactory::selects('cli', true));
    }

    public function testFpmWebSelectsDirect(): void
    {
        self::assertSame('direct', LokiSinkFactory::selects('fpm-fcgi', true));
    }

    public function testCgiFcgiWithoutFastcgiFinishRequestSelectsSpool(): void
    {
        self::assertSame('spool', LokiSinkFactory::selects('cgi-fcgi', false));
    }

    public function testCreateReturnsALokiSink(): void
    {
        $factory = new LokiSinkFactory(
            new DirectLokiSink(new LokiClient(new MockHttpClient(), new StubLokiEndpoint(pushUrl: null))),
            new SpoolLokiSink(sys_get_temp_dir()),
        );

        self::assertInstanceOf(DirectLokiSink::class, $factory->create());
    }
}
