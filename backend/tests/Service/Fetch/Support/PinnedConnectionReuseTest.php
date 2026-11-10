<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Support;

use App\Service\Fetch\Model\GuardedUrlModel;
use App\Service\Fetch\Support\EgressOptions;
use App\Tests\Support\KeepAliveServer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\CurlHttpClient;

final class PinnedConnectionReuseTest extends TestCase
{
    private const string HOST = 'pin.test';

    /** @var list<KeepAliveServer> */
    private array $servers = [];

    private int $port;

    protected function setUp(): void
    {
        $this->port = random_int(20000, 60000);
        foreach (['127.0.0.1' => 'server-1', '127.0.0.2' => 'server-2'] as $ip => $name) {
            $server = KeepAliveServer::start($ip, $this->port, $name);
            if (null === $server) {
                self::markTestSkipped("cannot serve on $ip:{$this->port} (macOS has no 127.0.0.2)");
            }
            $this->servers[] = $server;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
    }

    public function testAReusedConnectionGoesToThePinnedAddress(): void
    {
        $client = new CurlHttpClient();

        self::assertSame('server-1#1', $this->fetch($client, ['127.0.0.1']));
        self::assertSame('server-2#1', $this->fetch($client, ['127.0.0.2']));
        self::assertSame('server-1#1', $this->fetch($client, ['127.0.0.1']), 'a matching pin reuses its connection');
    }

    public function testANarrowerPinOpensItsOwnConnection(): void
    {
        $client = new CurlHttpClient();

        self::assertSame('server-1#1', $this->fetch($client, ['127.0.0.1', '127.0.0.2']));
        self::assertSame('server-2#1', $this->fetch($client, ['127.0.0.2']));
    }

    /** @param non-empty-list<string> $ips */
    private function fetch(CurlHttpClient $client, array $ips): string
    {
        $options = EgressOptions::merge(
            ['extra' => ['use_persistent_connections' => true]],
            EgressOptions::pinned(new GuardedUrlModel(self::HOST, $ips), 0),
        );

        return $client->request('GET', sprintf('http://%s:%d/', self::HOST, $this->port), $options)->getContent();
    }
}
