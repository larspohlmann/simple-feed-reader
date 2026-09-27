<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\ProxyConnection;
use App\Entity\ProxyServerSettings;
use App\Enum\ProxyType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CheckCatalogUrlsCommandProxyTest extends KernelTestCase
{
    private const string FEED_BODY
        = '<?xml version="1.0"?><rss version="2.0"><channel><title>x</title></channel></rss>';

    /**
     * @param array<int, array<string, mixed>> $recordedOptions
     */
    private function tester(array &$recordedOptions): CommandTester
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

        $application = new Application(self::$kernel ?? self::bootKernel());

        return new CommandTester($application->find('app:catalog:check-urls'));
    }

    private function enableAnEgressProxy(): void
    {
        $proxy = new ProxyServerSettings();
        $proxy->applyWithoutPassword(new ProxyConnection(true, true, ProxyType::Socks5, 'proxy.example', 1080, null));

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($proxy);
        $entityManager->flush();
    }

    public function testRequestCarriesTheProxyOptionWhenAnEgressProxyIsEnabled(): void
    {
        $recordedOptions = [];
        $tester = $this->tester($recordedOptions);
        $this->enableAnEgressProxy();

        $tester->execute(['--limit' => '1']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertNotEmpty($recordedOptions);
        self::assertArrayHasKey('proxy', $recordedOptions[0]);
        self::assertSame('socks5://proxy.example:1080', $recordedOptions[0]['proxy']);
    }

    public function testRequestCarriesNoProxyOptionWithoutAnEgressProxy(): void
    {
        $recordedOptions = [];
        $tester = $this->tester($recordedOptions);

        $tester->execute(['--limit' => '1']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertNotEmpty($recordedOptions);
        self::assertArrayNotHasKey('proxy', $recordedOptions[0]);
        self::assertSame(20.0, $recordedOptions[0]['timeout'] ?? null);
    }
}
