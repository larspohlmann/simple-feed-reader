<?php

declare(strict_types=1);

namespace App\Tests\Service\LinkCard;

use App\Http\SymfonyStatusReasonPhrases;
use App\Service\Fetch\DnsResolver\DnsResolverInterface;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\LinkCard\PageCardReader;
use App\Service\Reader\Exception\PageFetchException;
use App\Service\Reader\HtmlPageFetcher;
use App\Service\Reader\LandingChallenge;
use App\Service\Reader\MetaRefreshTarget;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\NoEgressProxy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PageCardReaderTest extends TestCase
{
    use NoEgressProxy;

    private const string SHARED = 'https://example.com/story';

    private function reader(MockResponse ...$responses): PageCardReader
    {
        $resolver = new class implements DnsResolverInterface {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34'];
            }
        };

        return new PageCardReader(new HtmlPageFetcher(
            FetchWiring::redirectFollower(
                new MockHttpClient($responses),
                $this->noEgressProxy(),
                new UrlGuard($resolver, new IpValidator()),
            ),
            new MetaRefreshTarget(),
            new LandingChallenge(),
            'TestAgent/1.0',
            new SymfonyStatusReasonPhrases(),
        ));
    }

    private function page(string $head): MockResponse
    {
        return new MockResponse('<html><head>' . $head . '</head><body>x</body></html>', ['http_code' => 200]);
    }

    public function testReadsTheOpenGraphCard(): void
    {
        $card = $this->reader($this->page(
            '<meta property="og:title" content="Headline"><meta property="og:description" content="Summary">'
            . '<meta property="og:image" content="https://cdn.example.com/a.jpg">'
            . '<meta property="og:site_name" content="Example News">',
        ))->read(self::SHARED);

        self::assertNotNull($card);
        self::assertSame(self::SHARED, $card->url);
        self::assertSame('Headline', $card->title);
        self::assertSame('Summary', $card->description);
        self::assertSame('https://cdn.example.com/a.jpg', $card->imageUrl);
        self::assertSame('Example News', $card->siteName);
    }

    public function testFallsBackToTwitterTagsAndTheHostForTheSite(): void
    {
        $card = $this->reader($this->page(
            '<meta name="twitter:title" content="Tweet title"><meta name="twitter:description" content="Tweet text">'
            . '<meta name="twitter:image" content="https://cdn.example.com/t.jpg">',
        ))->read('https://www.example.com/story');

        self::assertNotNull($card);
        self::assertSame('Tweet title', $card->title);
        self::assertSame('Tweet text', $card->description);
        self::assertSame('https://cdn.example.com/t.jpg', $card->imageUrl);
        self::assertSame('example.com', $card->siteName);
    }

    public function testFallsBackToTheTitleElementAndTheMetaDescription(): void
    {
        $card = $this->reader($this->page(
            "<title>  Plain\n title </title><meta name=\"description\" content=\"Plain text\">",
        ))->read(self::SHARED);

        self::assertNotNull($card);
        self::assertSame('Plain title', $card->title);
        self::assertSame('Plain text', $card->description);
        self::assertNull($card->imageUrl);
    }

    public function testResolvesARelativeImageAgainstTheFinalUrl(): void
    {
        $card = $this->reader($this->page(
            '<meta property="og:title" content="T"><meta property="og:image" content="/img/a.jpg">',
        ))->read(self::SHARED);

        self::assertSame('https://example.com/img/a.jpg', $card?->imageUrl);
    }

    public function testUpgradesAnHttpImage(): void
    {
        $card = $this->reader($this->page(
            '<meta property="og:title" content="T"><meta property="og:image" content="http://cdn.example.com/a.jpg">',
        ))->read(self::SHARED);

        self::assertSame('https://cdn.example.com/a.jpg', $card?->imageUrl);
    }

    public function testRefusesAJavascriptImage(): void
    {
        $card = $this->reader($this->page(
            '<meta property="og:title" content="T"><meta property="og:image" content="javascript:alert(1)">',
        ))->read(self::SHARED);

        self::assertNotNull($card);
        self::assertNull($card->imageUrl);
    }

    public function testAPageWithoutATitleYieldsNoCard(): void
    {
        self::assertNull($this->reader($this->page('<meta property="og:description" content="Only text">'))
            ->read(self::SHARED));
    }

    public function testCapsALongDescriptionOnAWordBoundary(): void
    {
        $long = trim(str_repeat('word ', 80));
        $card = $this->reader($this->page(
            '<meta property="og:title" content="T"><meta property="og:description" content="' . $long . '">',
        ))->read(self::SHARED);

        self::assertNotNull($card);
        self::assertNotNull($card->description);
        self::assertLessThanOrEqual(300, mb_strlen($card->description));
        self::assertStringEndsWith('word…', $card->description);
    }

    public function testTheCardUrlIsTheSharedUrlEvenAfterARedirect(): void
    {
        $card = $this->reader(
            new MockResponse('', [
                'http_code' => 301,
                'response_headers' => ['Location' => 'https://example.com/final'],
            ]),
            $this->page('<meta property="og:title" content="T"><meta property="og:image" content="pic.jpg">'),
        )->read('https://example.com/short');

        self::assertNotNull($card);
        self::assertSame('https://example.com/short', $card->url);
        self::assertSame('https://example.com/pic.jpg', $card->imageUrl);
    }

    public function testAFailedPagePropagates(): void
    {
        $this->expectException(PageFetchException::class);

        $this->reader(new MockResponse('', ['http_code' => 500]))->read(self::SHARED);
    }
}
