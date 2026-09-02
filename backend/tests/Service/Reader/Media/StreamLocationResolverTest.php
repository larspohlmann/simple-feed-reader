<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Fetch\DnsResolverInterface;
use App\Service\Fetch\FailoverRequestSender;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\ProxyEgressResolver;
use App\Service\Fetch\RedirectFollower;
use App\Service\Fetch\UrlGuard;
use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\DurableMediaUrl;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\MediaLanding;
use App\Service\Reader\Media\MediaUrlKind;
use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use App\Service\Reader\Media\StreamLocationResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class StreamLocationResolverTest extends TestCase
{
    private const string DECLARED = 'https://www.zdfheute.de/api/video/istaf-100.m3u8';
    private const string LANDING = 'https://zdfvod.akamaized.net/i/mp4/none/zdf/26/09/'
        . 'istaf,_508k,_808k,v17.mp4.csmil/master.m3u8';

    /** @var list<string> */
    private array $requested = [];

    /** @var array<array-key, mixed> */
    private array $seenOptions = [];

    /** @param list<MockResponse> $responses */
    private function resolver(array $responses): StreamLocationResolver
    {
        $queue = $responses;
        $client = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$queue): MockResponse {
                $this->requested[] = $url;
                $this->seenOptions = $options;

                return array_shift($queue) ?? new MockResponse('', ['http_code' => 500]);
            },
        );
        $dns = new class implements DnsResolverInterface {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34'];
            }
        };
        $proxy = $this->createStub(ProxyEgressResolver::class);
        $proxy->method('resolve')->willReturn(null);
        $providers = new EmbedProviders([new YouTubeEmbedProvider()]);

        return new StreamLocationResolver(
            new MediaLanding(
                new RedirectFollower(new FailoverRequestSender($client, $proxy), new UrlGuard($dns, new IpValidator())),
                'TestAgent/1.0',
            ),
            new MediaUrlKind(new DurableMediaUrl(), $providers),
        );
    }

    private static function redirect(string $location): MockResponse
    {
        return new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => $location]]);
    }

    private static function stream(): ArticleMedia
    {
        return new ArticleMedia([
            new MediaCandidate(MediaKind::Stream, self::DECLARED, 'https://www.zdfheute.de/poster.jpg', null, 'prose'),
        ]);
    }

    public function testReEmitsAStreamAtItsLanding(): void
    {
        $resolved = $this->resolver([self::redirect(self::LANDING), new MockResponse('#EXTM3U', ['http_code' => 200])])
            ->resolve(self::stream());

        self::assertSame(self::LANDING, $resolved->candidates[0]->url);
        self::assertSame(MediaKind::Stream, $resolved->candidates[0]->kind);
        self::assertSame('https://www.zdfheute.de/poster.jpg', $resolved->candidates[0]->posterUrl);
        self::assertSame('prose', $resolved->candidates[0]->precedingText);
    }

    public function testKeepsTheDeclaredUrlWhenTheChainLandsOnAnError(): void
    {
        $resolved = $this->resolver([self::redirect(self::LANDING), new MockResponse('', ['http_code' => 403])])
            ->resolve(self::stream());

        self::assertSame(self::DECLARED, $resolved->candidates[0]->url);
    }

    public function testKeepsTheDeclaredUrlWhenTheLandingIsTokenised(): void
    {
        $resolved = $this->resolver([
            self::redirect(self::LANDING . '?hdnts=exp=1'),
            new MockResponse('#EXTM3U', ['http_code' => 200]),
        ])->resolve(self::stream());

        self::assertSame(self::DECLARED, $resolved->candidates[0]->url);
    }

    public function testKeepsTheDeclaredUrlWhenTheChainFails(): void
    {
        $resolved = $this->resolver([new MockResponse('', ['error' => 'Connection reset by peer'])])
            ->resolve(self::stream());

        self::assertSame(self::DECLARED, $resolved->candidates[0]->url);
    }

    public function testKeepsTheDeclaredUrlWhenTheChainLandsOnAFile(): void
    {
        $resolved = $this->resolver([
            self::redirect('https://cdn.test/clip.mp4'),
            new MockResponse('', ['http_code' => 200]),
        ])->resolve(self::stream());

        self::assertSame(self::DECLARED, $resolved->candidates[0]->url);
    }

    public function testSendsTheHlsAcceptHeaderAgentAndTimeBudget(): void
    {
        $this->resolver([new MockResponse('#EXTM3U', ['http_code' => 200])])->resolve(self::stream());

        /** @var list<string> $headers */
        $headers = $this->seenOptions['headers'];
        self::assertContains('Accept: application/vnd.apple.mpegurl,application/x-mpegURL,*/*;q=0.8', $headers);
        self::assertContains('User-Agent: TestAgent/1.0', $headers);
        self::assertSame(10.0, $this->seenOptions['max_duration']);
    }

    public function testMakesNoRequestForAnythingButAStream(): void
    {
        $media = new ArticleMedia([
            new MediaCandidate(MediaKind::Video, 'https://a.test/clip.mp4', 'p.jpg'),
            new MediaCandidate(MediaKind::Audio, 'https://a.test/show.mp3'),
            new MediaCandidate(MediaKind::Embed, 'https://www.youtube-nocookie.com/embed/M1j_uRqKMKI', 'p.jpg'),
        ]);

        $resolved = $this->resolver([])->resolve($media);

        self::assertSame([], $this->requested);
        self::assertSame($media->candidates, $resolved->candidates);
    }
}
