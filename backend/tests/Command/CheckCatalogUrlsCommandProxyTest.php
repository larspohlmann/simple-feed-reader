<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Enum\ProxyType;
use App\Service\Fetch\ProxyConfig;
use App\Service\Fetch\ProxyEgressResolver;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CheckCatalogUrlsCommandProxyTest extends KernelTestCase
{
    private const string FEED_BODY = '<?xml version="1.0"?><rss version="2.0"><channel><title>x</title></channel></rss>';

    /**
     * @param array<int, array<string, mixed>> $recordedOptions
     */
    private function tester(array &$recordedOptions, ?ProxyEgressResolver $resolverStub = null): CommandTester
    {
        self::bootKernel();

        $client = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$recordedOptions): MockResponse {
                $recordedOptions[] = $options;

                return new MockResponse(self::FEED_BODY, [
                    'response_headers' => ['content-type' => ['application/rss+xml']],
                ]);
            },
        );

        self::getContainer()->set('catalog.rot_check.http_client', $client);
        if (null !== $resolverStub) {
            self::getContainer()->set(ProxyEgressResolver::class, $resolverStub);
        }

        $application = new Application(self::$kernel ?? self::bootKernel());

        return new CommandTester($application->find('app:catalog:check-urls'));
    }

    public function testRequestCarriesTheProxyOptionWhenTheResolverReturnsOne(): void
    {
        $proxyConfig = new ProxyConfig(ProxyType::Socks5, 'proxy.example', 1080, null, null);
        $resolverStub = $this->createStub(ProxyEgressResolver::class);
        $resolverStub->method('resolve')->willReturn($proxyConfig);

        $recordedOptions = [];
        $tester = $this->tester($recordedOptions, $resolverStub);
        $tester->execute(['--limit' => '1']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertNotEmpty($recordedOptions);
        self::assertArrayHasKey('proxy', $recordedOptions[0]);
        self::assertSame('socks5h://proxy.example:1080', $recordedOptions[0]['proxy']);
    }

    public function testRequestCarriesNoProxyOptionWhenTheResolverReturnsNull(): void
    {
        $recordedOptions = [];
        $tester = $this->tester($recordedOptions);
        $tester->execute(['--limit' => '1']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertNotEmpty($recordedOptions);
        self::assertArrayNotHasKey('proxy', $recordedOptions[0]);
    }
}
