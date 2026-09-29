<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Sibling;

use App\Service\Fetch\DnsResolver\DnsResolverInterface;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Reader\Media\DurableMediaUrl;
use App\Service\Reader\Media\EmbedProvider\YouTubeEmbedProvider;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaLanding;
use App\Service\Reader\Media\MediaUrlKind;
use App\Service\Reader\Media\Model\ArticleMediaModel;
use App\Service\Reader\Media\Model\MediaCandidateModel;
use App\Service\Reader\Media\Model\MediaKind;
use App\Service\Reader\Media\Sibling\NearbyPoster;
use App\Service\Reader\Media\Sibling\SiblingIdRule;
use App\Service\Reader\Media\Sibling\SiblingMediaExtender;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\NoEgressProxy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SiblingMediaExtenderTest extends TestCase
{
    use NoEgressProxy;

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
        $landing = new MediaLanding(
            FetchWiring::redirectFollower($client, $this->noEgressProxy(), new UrlGuard($dns, new IpValidator())),
            'TestAgent/1.0',
        );

        return new SiblingMediaExtender(
            new SiblingIdRule(new NearbyPoster()),
            $landing,
            new MediaUrlKind(new DurableMediaUrl(), new EmbedProviders([new YouTubeEmbedProvider()])),
        );
    }

    private static function redirect(string $location): MockResponse
    {
        return new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => $location]]);
    }

    private static function found(): ArticleMediaModel
    {
        return new ArticleMediaModel([new MediaCandidateModel(
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

    public function testSkipsASiblingAlreadyDeclaredAsASeedWithoutAskingTheNetwork(): void
    {
        // The page seeds the same clip twice — its VideoObject and its player
        // config (#1055) — so the search re-derives a seed already declared. The
        // extender must skip it before the redirect-follow, making no request.
        $declared = new ArticleMediaModel([
            self::found()->candidates[0],
            new MediaCandidateModel(
                MediaKind::Stream,
                'https://cdn.test/live/reaktion-anschlag-video-100.m3u8',
                'https://a.test/assets/reaktion~1920x1080',
            ),
        ]);

        $extended = $this->extender([])->extend($declared, $declared, self::PAGE);

        self::assertSame([], $this->requested);
        self::assertCount(2, $extended->candidates);
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
