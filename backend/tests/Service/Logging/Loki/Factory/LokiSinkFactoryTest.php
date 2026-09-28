<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging\Loki\Factory;

use App\Service\Logging\Loki\Factory\LokiSinkFactory;
use App\Service\Logging\Loki\LokiClient;
use App\Service\Logging\Loki\LokiSink\DirectLokiSink;
use App\Service\Logging\Loki\LokiSink\SpoolLokiSink;
use App\Service\Logging\Loki\Model\LokiDelivery;
use App\Tests\Support\StubLokiEndpoint;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class LokiSinkFactoryTest extends TestCase
{
    public function testCliAlwaysSelectsDirectEvenWithoutFastcgiFinishRequest(): void
    {
        self::assertSame(LokiDelivery::Direct, LokiSinkFactory::selects('cli', false));
        self::assertSame(LokiDelivery::Direct, LokiSinkFactory::selects('cli', true));
    }

    public function testFpmWebSelectsDirect(): void
    {
        self::assertSame(LokiDelivery::Direct, LokiSinkFactory::selects('fpm-fcgi', true));
    }

    public function testCgiFcgiWithoutFastcgiFinishRequestSelectsSpool(): void
    {
        self::assertSame(LokiDelivery::Spool, LokiSinkFactory::selects('cgi-fcgi', false));
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
