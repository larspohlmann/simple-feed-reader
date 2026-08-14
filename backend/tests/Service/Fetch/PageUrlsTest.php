<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\PageUrls;
use PHPUnit\Framework\TestCase;

final class PageUrlsTest extends TestCase
{
    public function testOriginKeepsSchemeHostAndPort(): void
    {
        $page = new PageUrls('https://site.test:8443/section/index.html?q=1');

        self::assertSame('https://site.test:8443', $page->origin());
    }

    public function testOriginIsNullWhenThePageNamesNoHost(): void
    {
        self::assertNull((new PageUrls('/section/'))->origin());
    }

    public function testPathIsThePagesOwnPath(): void
    {
        self::assertSame('/section/index.html', (new PageUrls('https://site.test/section/index.html'))->path());
    }

    public function testPathFallsBackToRootWhenThePageCarriesNone(): void
    {
        self::assertSame('/', (new PageUrls('https://site.test'))->path());
    }

    public function testResolveJoinsARelativeReferenceToThePageDirectory(): void
    {
        $page = new PageUrls('https://site.test/section/index.html');

        self::assertSame('https://site.test/section/article', $page->resolve('article'));
    }

    public function testResolveThrowsWhenThePageNamesNoHost(): void
    {
        $this->expectException(FeedUnreachableException::class);

        (new PageUrls('/section/'))->resolve('article');
    }

    public function testHttpUrlResolvesARelativeReference(): void
    {
        $page = new PageUrls('https://site.test/section/');

        self::assertSame('https://site.test/section/article', $page->httpUrl('article'));
    }

    public function testHttpUrlTrimsTheReference(): void
    {
        self::assertSame('https://site.test/a', (new PageUrls('https://site.test/'))->httpUrl("  /a\n"));
    }

    public function testHttpUrlIsNullForAMissingOrEmptyReference(): void
    {
        $page = new PageUrls('https://site.test/');

        self::assertNull($page->httpUrl(null));
        self::assertNull($page->httpUrl('   '));
    }

    public function testHttpUrlIsNullForANonHttpScheme(): void
    {
        $page = new PageUrls('https://site.test/');

        self::assertNull($page->httpUrl('javascript:alert(1)'));
        self::assertNull($page->httpUrl('mailto:hi@site.test'));
        self::assertNull($page->httpUrl('data:text/html,x'));
    }

    public function testHttpUrlKeepsAnAbsoluteHttpReference(): void
    {
        self::assertSame('http://other.test/a', (new PageUrls('https://site.test/'))->httpUrl('http://other.test/a'));
    }

    public function testHttpUrlIsNullWhenThePageNamesNoHost(): void
    {
        self::assertNull((new PageUrls('/section/'))->httpUrl('article'));
    }

    public function testIsPageItselfIgnoresATrailingSlash(): void
    {
        $page = new PageUrls('https://site.test/section');

        self::assertTrue($page->isPageItself('https://site.test/section/'));
        self::assertTrue($page->isPageItself('https://site.test/section'));
        self::assertFalse($page->isPageItself('https://site.test/section/article'));
    }
}
