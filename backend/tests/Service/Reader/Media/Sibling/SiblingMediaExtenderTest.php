<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Sibling;

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
use App\Service\Reader\Media\Sibling\SiblingIdRule;
use App\Service\Reader\Media\Sibling\SiblingMediaExtender;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SiblingMediaExtenderTest extends TestCase
{
    private const string PAGE = '<html><body><script>self.__next_f.push([1,"'
        . '{\\"config\\":{\\"isPriority\\":\\"$undefined\\",\\"content\\":\\"taktik-analyse-video-100\\",'
        . '\\"startImage\\":{\\"layouts\\":{\\"1920x1080\\":\\"https://a.test/assets/taktik~1920x1080\\"}}}},'
        . '{\\"config\\":{\\"isPriority\\":\\"$undefined\\",\\"content\\":\\"reaktion-anschlag-video-100\\",'
        . '\\"startImage\\":{\\"layouts\\":{\\"1920x1080\\":\\"https://a.test/assets/reaktion~1920x1080\\"}}}}'
        . '"])</script></body></html>';

    /** @var list<string> */
    private array $requested = [];

    /** @param list<MockResponse> $responses */
    private function extender(array $responses): SiblingMediaExtender
    {
        $queue = $responses;
        $client = new MockHttpClient(function (string $method, string $url) use (&$queue): MockResponse {
            $this->requested[] = $url;

            return array_shift($queue) ?? new MockResponse('', ['http_code' => 500]);
        });
        $dns = new class implements DnsResolverInterface {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34'];
            }
        };
        $proxy = $this->createStub(ProxyEgressResolver::class);
        $proxy->method('resolve')->willReturn(null);
        $landing = new MediaLanding(
            new RedirectFollower(new FailoverRequestSender($client, $proxy), new UrlGuard($dns, new IpValidator())),
            'TestAgent/1.0',
        );

        return new SiblingMediaExtender(
            new SiblingIdRule(),
            $landing,
            new MediaUrlKind(new DurableMediaUrl(), new EmbedProviders([new YouTubeEmbedProvider()])),
        );
    }

    private static function redirect(string $location): MockResponse
    {
        return new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => $location]]);
    }

    private static function found(): ArticleMedia
    {
        return new ArticleMedia([new MediaCandidate(
            MediaKind::Stream,
            'https://cdn.test/live/taktik-analyse-video-100.m3u8',
            'https://a.test/assets/taktik~1920x1080',
        )]);
    }

    public function testKeepsADerivedStreamAtItsLanding(): void
    {
        $landing = 'https://cdn.test/v2/reaktion-anschlag-video-100/master.m3u8';
        $extended = $this->extender([self::redirect($landing), new MockResponse('#EXTM3U', ['http_code' => 200])])
            ->extend(self::found(), self::found(), self::PAGE);

        self::assertCount(2, $extended->candidates);
        self::assertSame($landing, $extended->candidates[1]->url);
        self::assertSame(MediaKind::Stream, $extended->candidates[1]->kind);
        self::assertSame('https://a.test/assets/reaktion~1920x1080', $extended->candidates[1]->posterUrl);
        self::assertSame(['https://cdn.test/live/reaktion-anschlag-video-100.m3u8', $landing], $this->requested);
    }

    public function testDropsADerivedUrlTheNetworkRefuses(): void
    {
        $extended = $this->extender([new MockResponse('', ['http_code' => 404])])
            ->extend(self::found(), self::found(), self::PAGE);

        self::assertCount(1, $extended->candidates);
    }

    public function testDropsADerivedUrlThatLandsOnAnotherKind(): void
    {
        $extended = $this->extender([
            self::redirect('https://cdn.test/live/reaktion.mp4'),
            new MockResponse('', ['http_code' => 200]),
        ])->extend(self::found(), self::found(), self::PAGE);

        self::assertCount(1, $extended->candidates);
    }

    public function testMakesNoRequestWhenNothingIsDerived(): void
    {
        $extended = $this->extender([])
            ->extend(self::found(), self::found(), '<html><body><p>no payload</p></body></html>');

        self::assertSame([], $this->requested);
        self::assertCount(1, $extended->candidates);
    }
}
