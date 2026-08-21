<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery;

use App\Enum\SourceFormat;
use App\Service\Discovery\WordPressRestProbe;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\FetchResponse;
use App\Tests\Support\StubFeedFetcher;
use PHPUnit\Framework\TestCase;

final class WordPressRestProbeTest extends TestCase
{
    private const POSTS = 'https://site.example/wp-json/wp/v2/posts'
        . '?per_page=20&_fields=id,date_gmt,link,guid,title,content,excerpt,jetpack_featured_media_url';

    private function fetcher(): StubFeedFetcher
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willThrowForEverythingElse(new FeedUnreachableException('x: HTTP 404', 404));

        return $fetcher;
    }

    private function postsResponse(string $json): FetchResponse
    {
        return FetchResponse::fetched(
            self::POSTS,
            permanentRedirect: false,
            body: $json,
            etag: null,
            lastModified: null,
        );
    }

    private function headLinkPage(): string
    {
        return '<!doctype html><html><head><title>Site Example</title>'
            . '<link rel="https://api.w.org/" href="https://site.example/wp-json/">'
            . '</head><body>Hi</body></html>';
    }

    public function testOffersACandidateFromTheHeadLink(): void
    {
        $fetcher = $this->fetcher();
        $fetcher->willReturn(self::POSTS, $this->postsResponse('[{"id":1}]'));

        $candidate = (new WordPressRestProbe($fetcher))->offer($this->headLinkPage(), 'https://site.example/');

        self::assertNotNull($candidate);
        self::assertSame(self::POSTS, $candidate->url);
        self::assertSame('Site Example', $candidate->title);
        self::assertSame(SourceFormat::WP_JSON, $candidate->format);
    }

    public function testFallsBackToTheDefaultRootBehindAFingerprint(): void
    {
        $fetcher = $this->fetcher();
        $fetcher->willReturn(self::POSTS, $this->postsResponse('[{"id":9}]'));

        // No api.w.org link, but a wp-content asset path betrays WordPress.
        $body = '<!doctype html><html><head><title>Fp</title></head>'
            . '<body><img src="https://site.example/wp-content/uploads/x.jpg"></body></html>';

        $candidate = (new WordPressRestProbe($fetcher))->offer($body, 'https://site.example/');

        self::assertSame(self::POSTS, $candidate?->url);
        self::assertSame([self::POSTS], $fetcher->fetchedUrls);
    }

    public function testNoLinkAndNoFingerprintMakesNoRequest(): void
    {
        $fetcher = $this->fetcher();

        $candidate = (new WordPressRestProbe($fetcher))->offer(
            '<!doctype html><html><head><title>Plain</title></head><body>Hi</body></html>',
            'https://site.example/',
        );

        self::assertNull($candidate);
        self::assertSame([], $fetcher->fetchedUrls);
    }

    public function testAGatedEndpointYieldsNoCandidate(): void
    {
        $fetcher = $this->fetcher();
        $fetcher->willThrow(self::POSTS, new FeedUnreachableException('x: HTTP 401', 401));

        self::assertNull((new WordPressRestProbe($fetcher))->offer($this->headLinkPage(), 'https://site.example/'));
    }

    public function testAnEmptyPostArrayYieldsNoCandidate(): void
    {
        $fetcher = $this->fetcher();
        $fetcher->willReturn(self::POSTS, $this->postsResponse('[]'));

        self::assertNull((new WordPressRestProbe($fetcher))->offer($this->headLinkPage(), 'https://site.example/'));
    }

    public function testARestRouteQueryRootIsUnsupported(): void
    {
        $fetcher = $this->fetcher();
        $body = '<!doctype html><html><head><title>Q</title>'
            . '<link rel="https://api.w.org/" href="https://site.example/?rest_route=/">'
            . '</head><body>Hi</body></html>';

        self::assertNull((new WordPressRestProbe($fetcher))->offer($body, 'https://site.example/'));
        self::assertSame([], $fetcher->fetchedUrls);
    }

    public function testTheProbeUrlDropsEmbedAndPrunesFields(): void
    {
        $fetcher = $this->fetcher();
        $fetcher->willReturn(self::POSTS, $this->postsResponse('[{"id":1}]'));

        $candidate = (new WordPressRestProbe($fetcher))->offer($this->headLinkPage(), 'https://site.example/');

        self::assertNotNull($candidate);
        self::assertStringNotContainsString('_embed', $candidate->url);
        self::assertStringContainsString('per_page=20', $candidate->url);
        self::assertStringContainsString('_fields=', $candidate->url);
    }
}
