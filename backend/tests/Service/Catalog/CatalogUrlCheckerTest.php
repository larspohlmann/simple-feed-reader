<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog;

use App\Service\Catalog\BundledCatalog;
use App\Service\Catalog\CatalogUrlChecker;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CatalogUrlCheckerTest extends KernelTestCase
{
    private const string FEED_BODY = '<?xml version="1.0"?><rss version="2.0"><channel><title>x</title></channel>'
        . '</rss>';

    public function testAUrlThatAnswersWithAnErrorStatusIsReportedBroken(): void
    {
        $checker = $this->checker(new MockHttpClient(
            static fn (): MockResponse => new MockResponse('', ['http_code' => 404]),
        ));
        $firstFeed = $this->bundled()->document()->categories[0]->feeds[0];

        $report = $checker->check(1);

        self::assertSame(1, $report->checked);
        self::assertFalse($report->isHealthy());
        self::assertCount(1, $report->broken);
        self::assertSame($firstFeed->title, $report->broken[0]->title);
        self::assertSame($firstFeed->url, $report->broken[0]->url);
        self::assertSame('HTTP 404', $report->broken[0]->reason);
    }

    public function testUrlsThatServeAFeedLeaveTheReportHealthy(): void
    {
        $requestOptions = [];
        $checker = $this->checker(new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$requestOptions): MockResponse {
                $requestOptions[] = $options;

                return new MockResponse(self::FEED_BODY);
            },
        ));

        $report = $checker->check(2);

        self::assertSame(2, $report->checked);
        self::assertSame([], $report->broken);
        self::assertTrue($report->isHealthy());
        /** @var array{normalized_headers: array{'user-agent': list<string>}} $firstRequestOptions */
        $firstRequestOptions = $requestOptions[0];
        self::assertSame(
            ['User-Agent: SimpleFeedReader/1.0'],
            $firstRequestOptions['normalized_headers']['user-agent'],
        );
    }

    public function testNoLimitChecksTheWholeShippedCatalog(): void
    {
        $checker = $this->checker(new MockHttpClient(static fn (): MockResponse => new MockResponse(self::FEED_BODY)));

        $report = $checker->check(null);

        self::assertSame($this->bundled()->document()->feedCount(), $report->checked);
    }

    private function checker(MockHttpClient $client): CatalogUrlChecker
    {
        self::getContainer()->set('catalog.rot_check.http_client', $client);
        $checker = self::getContainer()->get(CatalogUrlChecker::class);
        self::assertInstanceOf(CatalogUrlChecker::class, $checker);

        return $checker;
    }

    private function bundled(): BundledCatalog
    {
        $bundled = self::getContainer()->get(BundledCatalog::class);
        self::assertInstanceOf(BundledCatalog::class, $bundled);

        return $bundled;
    }
}
