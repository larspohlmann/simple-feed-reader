<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Source;

use App\Service\Reader\Media\DurableMediaUrl;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\MediaUrlKind;
use App\Service\Reader\Media\RawPage;
use App\Service\Reader\Media\Source\ZdfPlayerConfigSource;
use PHPUnit\Framework\TestCase;

final class ZdfPlayerConfigSourceTest extends TestCase
{
    private const string PAGE_URL = 'https://www.zdfheute.de/politik/ausland/trump-ki-risiken-schwindel-100.html';

    private ZdfPlayerConfigSource $source;

    protected function setUp(): void
    {
        $this->source = new ZdfPlayerConfigSource(new MediaUrlKind(new DurableMediaUrl(), new EmbedProviders([])));
    }

    /** @return list<\App\Service\Reader\Media\MediaCandidate> */
    private function find(string $html, string $url): array
    {
        return $this->source->find(RawPage::parse($html, $url));
    }

    /** The page names each clip in a player config, escaped as it ships in the Next.js payload. */
    public function testSeedsAStreamPerPlayerConfigOnThePageHost(): void
    {
        $html = $this->page(
            $this->config('kuenstliche-intelligenz-gefahr-menschen-video-100', 'ki-gefahr-100')
            . $this->config('sgs-sievers-holz-100', 'clip-2-hjo-100'),
        );

        $found = $this->find($html, self::PAGE_URL);

        self::assertCount(2, $found);
        self::assertSame(MediaKind::Stream, $found[0]->kind);
        self::assertSame(
            'https://www.zdfheute.de/api/video/kuenstliche-intelligenz-gefahr-menschen-video-100.m3u8',
            $found[0]->url,
        );
        self::assertNotNull($found[0]->posterUrl);
        self::assertStringContainsString('ki-gefahr-100', (string) $found[0]->posterUrl);
        self::assertSame('https://www.zdfheute.de/api/video/sgs-sievers-holz-100.m3u8', $found[1]->url);
    }

    public function testBuildsTheStreamUrlOnTheServingHost(): void
    {
        $html = $this->page($this->config('hubschrauber-vorfall-daenemark-russland-video-100', 'daenemark-sar-100'));

        $found = $this->find($html, 'https://www.zdf.de/nachrichten/politik/x-100.html');

        self::assertCount(1, $found);
        self::assertSame(
            'https://www.zdf.de/api/video/hubschrauber-vorfall-daenemark-russland-video-100.m3u8',
            $found[0]->url,
        );
    }

    public function testIgnoresPagesThatAreNotZdf(): void
    {
        $html = $this->page($this->config('some-clip-100', 'some-still-100'));

        self::assertSame([], $this->find($html, 'https://www.tagesschau.de/ausland/x-100.html'));
    }

    /** A bare `content` key is a layout value, not a player: only one beside a startImage names a clip. */
    public function testIgnoresAContentKeyThatIsNotAPlayerConfig(): void
    {
        $html = $this->page('{\"content\":\"black-translucent\",\"theme\":\"zdf\"}');

        self::assertSame([], $this->find($html, self::PAGE_URL));
    }

    public function testSkipsAConfigWithNoStillNearby(): void
    {
        $html = $this->page('{\"content\":\"lonely-clip-100\",\"startImage\":{\"title\":\"no image url here\"}}');

        self::assertSame([], $this->find($html, self::PAGE_URL));
    }

    public function testReportsEachClipOnce(): void
    {
        $html = $this->page(
            $this->config('repeated-clip-100', 'repeated-still-100')
            . $this->config('repeated-clip-100', 'repeated-still-100'),
        );

        self::assertCount(1, $this->find($html, self::PAGE_URL));
    }

    private function page(string $payload): string
    {
        return '<html lang="de"><body><script>self.__next_f.push([1,"' . $payload . '"])</script></body></html>';
    }

    /** The escaped shape the payload ships: a content id, a startImage, and its rendition layouts. */
    private function config(string $clipId, string $stillId): string
    {
        return '{\"config\":{\"content\":\"' . $clipId . '\",\"startImage\":{\"title\":\"A clip\",'
            . '\"layouts\":{\"1920x1080\":\"https://www.zdfheute.de/assets/' . $stillId . '~1920x1080?cb=1\"}}}},';
    }
}
