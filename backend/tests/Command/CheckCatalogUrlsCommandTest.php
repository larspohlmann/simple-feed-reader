<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CheckCatalogUrlsCommandTest extends KernelTestCase
{
    private function tester(MockHttpClient $client): CommandTester
    {
        self::bootKernel();
        self::getContainer()->set('catalog.rot_check.http_client', $client);
        $application = new Application(self::$kernel ?? self::bootKernel());

        return new CommandTester($application->find('app:catalog:check-urls'));
    }

    public function testExitsNonZeroWhenAUrlDoesNotServeAFeed(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            '<html lang="en"><body>not a feed</body></html>',
            ['response_headers' => ['content-type' => ['text/html']]],
        ));

        $tester = $this->tester($client);
        $tester->execute(['--limit' => '1']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('not a feed', $tester->getDisplay());
    }

    public function testExitsZeroWhenEveryCheckedUrlServesAFeed(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            '<?xml version="1.0"?><rss version="2.0"><channel><title>x</title></channel></rss>',
            ['response_headers' => ['content-type' => ['application/rss+xml']]],
        ));

        $tester = $this->tester($client);
        $tester->execute(['--limit' => '1']);

        self::assertSame(0, $tester->getStatusCode());
    }
}
