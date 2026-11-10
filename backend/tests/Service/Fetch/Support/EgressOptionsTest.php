<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Support;

use App\Enum\ProxyType;
use App\Service\Fetch\Model\GuardedUrlModel;
use App\Service\Fetch\Model\ProxyConfigModel;
use App\Service\Fetch\Support\EgressOptions;
use PHPUnit\Framework\TestCase;

final class EgressOptionsTest extends TestCase
{
    public function testProxiedYieldsTheProxyOption(): void
    {
        $proxy = new ProxyConfigModel(ProxyType::Socks5, 'p', 1080, null, null);

        self::assertSame('socks5://p:1080', EgressOptions::proxied($proxy)['proxy']);
    }

    /**
     * Left unset, curl reads no_proxy/NO_PROXY from the environment and sends a
     * matching host direct — succeeding silently, with no transport failure for
     * the caller to see, which would defeat `directFallback` off entirely.
     */
    public function testProxiedPinsNoProxyEmptySoTheEnvironmentCannotBypassIt(): void
    {
        $proxy = new ProxyConfigModel(ProxyType::Socks5, 'p', 1080, null, null);

        self::assertSame('', EgressOptions::proxied($proxy)['no_proxy']);
    }

    public function testProxiedForcesIpv4WhenTheProxyResolvesNamesLocally(): void
    {
        $proxy = new ProxyConfigModel(ProxyType::Socks5, 'p', 1080, null, null); // remoteDns off → socks5

        $extra = EgressOptions::proxied($proxy)['extra'] ?? null;

        self::assertNotNull($extra);
        self::assertSame(\CURL_IPRESOLVE_V4, $extra['curl'][\CURLOPT_IPRESOLVE]);
    }

    public function testProxiedDoesNotForceIpv4WhenTheProxyResolvesNames(): void
    {
        // remoteDns on → socks5h → curl hands the proxy the name, not an address.
        $proxy = new ProxyConfigModel(ProxyType::Socks5, 'p', 1080, null, null, true, true);

        self::assertArrayNotHasKey('extra', EgressOptions::proxied($proxy));
    }

    public function testProxiedDoesNotForceIpv4ForAnHttpProxy(): void
    {
        $proxy = new ProxyConfigModel(ProxyType::Http, 'p', 8080, null, null);

        self::assertArrayNotHasKey('extra', EgressOptions::proxied($proxy));
    }

    public function testPinnedPinsTheHostAndItsConnectToAlias(): void
    {
        $guarded = new GuardedUrlModel('example.com', ['93.184.216.34']);

        $options = EgressOptions::pinned($guarded, 0);

        self::assertCount(2, $options['resolve']);
        self::assertSame('93.184.216.34', $options['resolve']['example.com']);
        $alias = $this->aliasOf($options);
        self::assertMatchesRegularExpression('/^pin-[0-9a-f]{16}\.invalid$/', $alias);
        self::assertSame('93.184.216.34', $options['resolve'][$alias]);
        self::assertSame(["example.com::$alias:"], $options['extra']['curl'][\CURLOPT_CONNECT_TO]);
        self::assertArrayNotHasKey('proxy', $options);
    }

    public function testPinnedGivesEachAddressSetItsOwnAlias(): void
    {
        $dualStack = ['2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34'];

        $bothFamilies = $this->aliasOf(EgressOptions::pinned(new GuardedUrlModel('dual.example.com', $dualStack), 0));
        $ipv4Only = $this->aliasOf(EgressOptions::pinned(new GuardedUrlModel('dual.example.com', $dualStack), 1));
        $again = $this->aliasOf(EgressOptions::pinned(new GuardedUrlModel('other.example.com', $dualStack), 0));

        self::assertNotSame($bothFamilies, $ipv4Only);
        self::assertSame($bothFamilies, $again);
    }

    public function testPinnedBracketsAnIpv6LiteralHostInTheConnectTo(): void
    {
        $literal = '2606:2800:220:1:248:1893:25c8:1946';

        $options = EgressOptions::pinned(new GuardedUrlModel($literal, [$literal]), 0);

        self::assertStringStartsWith("[$literal]::pin-", $options['extra']['curl'][\CURLOPT_CONNECT_TO][0]);
    }

    public function testPinnedNeverForcesAFreshConnection(): void
    {
        $guarded = new GuardedUrlModel('dual.example.com', ['2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34']);

        foreach ([0, 1] as $attempt) {
            $curlOptions = EgressOptions::pinned($guarded, $attempt)['extra']['curl'];

            self::assertArrayNotHasKey(\CURLOPT_FRESH_CONNECT, $curlOptions);
        }
    }

    public function testMergeKeepsTheCallersExtraNextToTheEgressExtra(): void
    {
        $pinned = EgressOptions::pinned(new GuardedUrlModel('example.com', ['93.184.216.34']), 0);

        $merged = EgressOptions::merge(['extra' => ['use_persistent_connections' => true], 'timeout' => 1], $pinned);

        self::assertSame(1, $merged['timeout']);
        self::assertSame($pinned['resolve'], $merged['resolve']);
        self::assertIsArray($merged['extra']);
        self::assertTrue($merged['extra']['use_persistent_connections']);
        self::assertSame($pinned['extra']['curl'], $merged['extra']['curl']);
    }

    public function testMergeLetsTheEgressConnectToReplaceTheCallersListWholesale(): void
    {
        $pinned = EgressOptions::pinned(new GuardedUrlModel('example.com', ['93.184.216.34']), 0);

        $merged = EgressOptions::merge(['extra' => ['curl' => [\CURLOPT_CONNECT_TO => ['a', 'b']]]], $pinned);

        self::assertSame($pinned['extra']['curl'][\CURLOPT_CONNECT_TO], $this->curlOf($merged)[\CURLOPT_CONNECT_TO]);
    }

    public function testMergeKeepsThePersistentFlagNextToTheProxiedIpResolve(): void
    {
        $proxy = new ProxyConfigModel(ProxyType::Socks5, 'p', 1080, null, null);

        $persistent = ['extra' => ['use_persistent_connections' => true]];

        $merged = EgressOptions::merge($persistent, EgressOptions::proxied($proxy));

        self::assertIsArray($merged['extra']);
        self::assertTrue($merged['extra']['use_persistent_connections']);
        self::assertSame(\CURL_IPRESOLVE_V4, $this->curlOf($merged)[\CURLOPT_IPRESOLVE]);
    }

    public function testMergeWithoutAnyExtraAddsNone(): void
    {
        $merged = EgressOptions::merge(['timeout' => 1], ['proxy' => 'socks5://p:1080']);

        self::assertSame(['timeout' => 1, 'proxy' => 'socks5://p:1080'], $merged);
    }

    public function testPinnedClampsAnOutOfRangeAttemptToTheLastAvailablePin(): void
    {
        $guarded = new GuardedUrlModel('single-family.example.com', ['93.184.216.34']);

        $options = EgressOptions::pinned($guarded, 1);

        self::assertSame('93.184.216.34', $options['resolve']['single-family.example.com']);
    }

    /**
     * @param array<string, mixed> $merged
     *
     * @return array<mixed>
     */
    private function curlOf(array $merged): array
    {
        self::assertIsArray($merged['extra']);
        self::assertIsArray($merged['extra']['curl']);

        return $merged['extra']['curl'];
    }

    /** @param array{resolve: array<string, string>} $options */
    private function aliasOf(array $options): string
    {
        $aliases = array_values(array_filter(
            array_keys($options['resolve']),
            static fn (string $host): bool => str_starts_with($host, 'pin-'),
        ));
        self::assertCount(1, $aliases);

        return $aliases[0];
    }
}
