<?php

declare(strict_types=1);

namespace App\Tests\DependencyInjection;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\Internal\CurlClientState;

final class DisableHttpServerPushPassTest extends KernelTestCase
{
    public function testTheWiredTransportAcceptsNoServerPushes(): void
    {
        $transport = self::getContainer()->get('http_client.transport');

        self::assertInstanceOf(CurlHttpClient::class, $transport);
        self::assertSame(0, $this->maxPendingPushesOf($transport));
    }

    private function maxPendingPushesOf(CurlHttpClient $client): mixed
    {
        $state = (new \ReflectionProperty(CurlHttpClient::class, 'multi'))->getValue($client);
        self::assertInstanceOf(CurlClientState::class, $state);

        return (new \ReflectionProperty(CurlClientState::class, 'maxPendingPushes'))->getValue($state);
    }
}
