<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Support;

use App\Service\Fetch\DnsResolver\DnsResolverInterface;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\Model\GuardedUrlModel;
use App\Service\Fetch\Support\EgressOptions;
use App\Service\Fetch\UrlGuard;
use App\Tests\Support\KeepAliveServer;
use App\Tests\Support\KeepAliveServerUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\CurlHttpClient;

final class PinnedConnectionReuseTest extends TestCase
{
    private const int PORT_ATTEMPTS = 5;

    /** @var list<KeepAliveServer> */
    private array $servers = [];

    private int $port;

    protected function setUp(): void
    {
        $probe = @stream_socket_server('tcp://[::1]:0');
        if (false === $probe) {
            self::markTestSkipped('loopback IPv6 ([::1]) is unavailable on this host');
        }
        fclose($probe);

        $this->port = $this->startBothServers();
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

        self::assertSame('server-1#1', $this->fetch($client, 'pin.test', ['127.0.0.1']));
        self::assertSame('server-2#1', $this->fetch($client, 'pin.test', ['::1']));
        $again = $this->fetch($client, 'pin.test', ['127.0.0.1']);
        self::assertSame('server-1#1', $again, 'a matching pin reuses its connection');
    }

    public function testAMatchingPinReusesItsConnectionAcrossClientInstances(): void
    {
        self::assertSame('server-1#1', $this->fetch(new CurlHttpClient(), 'pin.test', ['127.0.0.1']));

        self::assertSame(
            'server-1#1',
            $this->fetch(new CurlHttpClient(), 'pin.test', ['127.0.0.1']),
            'only the process-wide persistent share can hand a connection to a new client',
        );
    }

    public function testANarrowerPinOpensItsOwnConnection(): void
    {
        $client = new CurlHttpClient();

        // Happy eyeballs decides which family answers a two-address pin, so only the narrower pin is deterministic.
        $first = $this->fetch($client, 'pin.test', ['127.0.0.1', '::1']);
        $second = $this->fetch($client, 'pin.test', ['::1']);

        self::assertMatchesRegularExpression('/^server-[12]#1$/', $first);
        self::assertMatchesRegularExpression('/^server-2#\d+$/', $second);
        self::assertNotSame($first, $second, 'the narrower pin must not inherit the wider pin\'s connection');
    }

    public function testAnInternationalHostIsPinnedToo(): void
    {
        $client = new CurlHttpClient();
        $host = $this->hostTheGuardYields('http://Bücher.test/');

        self::assertSame('server-1#1', $this->fetch($client, $host, ['127.0.0.1'], 'Bücher.test'));
        self::assertSame('server-2#1', $this->fetch($client, $host, ['::1'], 'Bücher.test'));
    }

    private function startBothServers(): int
    {
        $failures = [];
        for ($attempt = 0; $attempt < self::PORT_ATTEMPTS; ++$attempt) {
            $port = random_int(20000, 60000);
            try {
                $this->servers = [
                    KeepAliveServer::start('127.0.0.1', $port, 'server-1'),
                    KeepAliveServer::start('::1', $port, 'server-2'),
                ];

                return $port;
            } catch (KeepAliveServerUnavailableException $exception) {
                $this->tearDown();
                $this->servers = [];
                $failures[] = $exception->getMessage();
            }
        }

        self::fail('no free loopback port after ' . self::PORT_ATTEMPTS . ' attempts: ' . implode('; ', $failures));
    }

    private function hostTheGuardYields(string $url): string
    {
        $resolver = new class implements DnsResolverInterface {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34'];
            }
        };

        return (new UrlGuard($resolver, new IpValidator()))->assertSafe($url)->host;
    }

    /** @param non-empty-list<string> $ips */
    private function fetch(CurlHttpClient $client, string $pinnedHost, array $ips, ?string $urlHost = null): string
    {
        $options = EgressOptions::merge(
            ['extra' => ['use_persistent_connections' => true]],
            EgressOptions::pinned(new GuardedUrlModel($pinnedHost, $ips), 0),
        );
        $url = sprintf('http://%s:%d/', $urlHost ?? $pinnedHost, $this->port);

        return $client->request('GET', $url, $options)->getContent();
    }
}
