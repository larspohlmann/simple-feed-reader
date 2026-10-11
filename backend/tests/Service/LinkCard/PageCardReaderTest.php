<?php

declare(strict_types=1);

namespace App\Tests\Service\LinkCard;

use App\Service\Fetch\DnsResolver\DnsResolverInterface;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\LinkCard\PageCardReader;
use App\Service\Reader\Exception\PageFetchException;
use App\Service\Reader\PageHeadFetcher;
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

        return new PageCardReader(new PageHeadFetcher(FetchWiring::pageLanding(FetchWiring::redirectFollower(
            new MockHttpClient($responses),
            $this->noEgressProxy(),
            new UrlGuard($resolver, new IpValidator()),
        ))));
    }

    private function page(string $head): MockResponse
    {
        return new MockResponse('<html><head>' . $head . '</head><body>x</body></html>', ['http_code' => 200]);
    }

    public function testReadsTheCardOfAPageWhoseHeadRunsPastHalfAMegabyte(): void
    {
        $inlineScripts = '<script>' . str_repeat('x', 780_000) . '</script>';

        $card = $this->reader($this->page($inlineScripts . '<meta property="og:title" content="Strawberry cake">'))
            ->read(self::SHARED)->card;

        self::assertSame('Strawberry cake', $card?->title);
    }

    public function testReadsTheOpenGraphCard(): void
    {
        $card = $this->reader($this->page(
            '<meta property="og:title" content="Headline"><meta property="og:description" content="Summary">'
            . '<meta property="og:image" content="https://cdn.example.com/a.jpg">'
            . '<meta property="og:site_name" content="Example News">',
        ))->read(self::SHARED)->card;

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
        ))->read('https://www.example.com/story')->card;

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
        ))->read(self::SHARED)->card;

        self::assertNotNull($card);
        self::assertSame('Plain title', $card->title);
        self::assertSame('Plain text', $card->description);
        self::assertNull($card->imageUrl);
    }

    public function testResolvesARelativeImageAgainstTheFinalUrl(): void
    {
        $card = $this->reader($this->page(
            '<meta property="og:title" content="T"><meta property="og:image" content="/img/a.jpg">',
        ))->read(self::SHARED)->card;

        self::assertSame('https://example.com/img/a.jpg', $card?->imageUrl);
    }

    public function testUpgradesAnHttpImage(): void
    {
        $card = $this->reader($this->page(
            '<meta property="og:title" content="T"><meta property="og:image" content="http://cdn.example.com/a.jpg">',
        ))->read(self::SHARED)->card;

        self::assertSame('https://cdn.example.com/a.jpg', $card?->imageUrl);
    }

    public function testABlankOpenGraphImageFallsBackToTwitter(): void
    {
        $card = $this->reader($this->page(
            '<meta property="og:title" content="T"><meta property="og:image" content=" ">'
            . '<meta name="twitter:image" content="https://cdn.example.com/t.jpg">',
        ))->read(self::SHARED)->card;

        self::assertSame('https://cdn.example.com/t.jpg', $card?->imageUrl);
    }

    public function testRefusesAJavascriptImage(): void
    {
        $card = $this->reader($this->page(
            '<meta property="og:title" content="T"><meta property="og:image" content="javascript:alert(1)">',
        ))->read(self::SHARED)->card;

        self::assertNotNull($card);
        self::assertNull($card->imageUrl);
    }

    public function testAPageWithoutATitleYieldsNoCard(): void
    {
        self::assertNull($this->reader($this->page('<meta property="og:description" content="Only text">'))
            ->read(self::SHARED)->card);
    }

    public function testCapsALongDescriptionOnAWordBoundary(): void
    {
        $long = trim(str_repeat('word ', 80));
        $card = $this->reader($this->page(
            '<meta property="og:title" content="T"><meta property="og:description" content="' . $long . '">',
        ))->read(self::SHARED)->card;

        self::assertNotNull($card);
        self::assertNotNull($card->description);
        self::assertLessThanOrEqual(300, mb_strlen($card->description));
        self::assertStringEndsWith('word…', $card->description);
    }

    public function testACutDescriptionDoesNotEndOnPunctuationBeforeTheEllipsis(): void
    {
        $long = str_repeat('word ', 58) . 'word, trailing text that runs on and on';
        $card = $this->reader($this->page(
            '<meta property="og:title" content="T"><meta property="og:description" content="' . $long . '">',
        ))->read(self::SHARED)->card;

        self::assertStringEndsWith('word…', (string) $card?->description);
    }

    public function testTheCardUrlIsTheSharedUrlEvenAfterARedirect(): void
    {
        $card = $this->reader(
            new MockResponse('', [
                'http_code' => 301,
                'response_headers' => ['Location' => 'https://example.com/final'],
            ]),
            $this->page('<meta property="og:title" content="T"><meta property="og:image" content="pic.jpg">'),
        )->read('https://example.com/short')->card;

        self::assertNotNull($card);
        self::assertSame('https://example.com/short', $card->url);
        self::assertSame('https://example.com/pic.jpg', $card->imageUrl);
    }

    public function testAnArticleDeclaresNoMedium(): void
    {
        $read = $this->reader($this->page('<meta property="og:type" content="article">'
            . '<meta property="og:title" content="Headline">'
            . '<meta property="og:image" content="https://cdn.example.com/a.jpg">'))
            ->read(self::SHARED);

        self::assertNull($read->medium);
        self::assertNotNull($read->card);
    }

    public function testAnImageFileIsItsOwnMedium(): void
    {
        $read = $this->reader(new MockResponse("\x89PNG\r\n", [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'image/png'],
        ]))->read('https://i.example.com/chart');

        self::assertNotNull($read->medium);
        self::assertSame('https://i.example.com/chart', $read->medium->url);
        self::assertSame('image', $read->medium->kind);
    }

    public function testAVideoFilePageDeclaresItsVideoWithThePoster(): void
    {
        $read = $this->reader($this->page('<meta property="og:title" content="Clip">'
            . '<meta property="og:video" content="https://cdn.example.com/clip.mp4">'
            . '<meta property="og:image" content="https://cdn.example.com/poster.jpg">'))->read(self::SHARED);

        self::assertNotNull($read->medium);
        self::assertSame('https://cdn.example.com/clip.mp4', $read->medium->url);
        self::assertSame('https://cdn.example.com/poster.jpg', $read->medium->previewImageUrl);
    }

    public function testAPlayerPageIsNoMedium(): void
    {
        $read = $this->reader($this->page('<meta property="og:type" content="video.other">'
            . '<meta property="og:title" content="V">'
            . '<meta property="og:video:url" content="https://player.vimeo.com/video/1">'))
            ->read(self::SHARED);

        self::assertNull($read->medium);
    }

    public function testAPhotoPageDeclaresItsImage(): void
    {
        $read = $this->reader($this->page('<meta name="twitter:card" content="photo">'
            . '<meta property="og:title" content="P">'
            . '<meta property="og:image" content="https://cdn.example.com/photo.jpg">'))
            ->read(self::SHARED);

        self::assertNotNull($read->medium);
        self::assertSame('https://cdn.example.com/photo.jpg', $read->medium->url);
        self::assertSame('image', $read->medium->kind);
    }

    public function testAFailedPagePropagates(): void
    {
        $this->expectException(PageFetchException::class);

        $this->reader(new MockResponse('', ['http_code' => 500]))->read(self::SHARED)->card;
    }
}
