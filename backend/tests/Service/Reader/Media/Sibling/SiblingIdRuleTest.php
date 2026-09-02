<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Sibling;

use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\Sibling\SiblingIdRule;
use PHPUnit\Framework\TestCase;

final class SiblingIdRuleTest extends TestCase
{
    private const string SEED_URL = 'https://a.test/api/video/taktik-analyse-video-100.m3u8';

    private function seed(MediaKind $kind = MediaKind::Stream, string $url = self::SEED_URL): ArticleMedia
    {
        $candidate = new MediaCandidate($kind, $url, 'https://a.test/assets/taktik~1920x1080', null, 'prose');

        return new ArticleMedia([$candidate]);
    }

    /** One player config in the shape the ZDF payload uses, escaped as the flight data escapes it. */
    private static function config(string $id, ?string $still = null): string
    {
        $layouts = $still === null
            ? ''
            : ',\\"startImage\\":{\\"layouts\\":{\\"384x216\\":\\"https://a.test/assets/' . $still . '~384x216\\",'
                . '\\"1920x1080\\":\\"https://a.test/assets/' . $still . '~1920x1080?cb=1\\"}}';

        return '{\\"config\\":{\\"isPriority\\":\\"$undefined\\",\\"content\\":\\"' . $id . '\\"' . $layouts . '}}';
    }

    private static function page(string ...$payload): string
    {
        return '<html><body><script>self.__next_f.push([1,"' . implode(',', $payload) . '"])</script></body></html>';
    }

    public function testDerivesTheSiblingsNamedInTheSeedsContext(): void
    {
        $html = self::page(
            self::config('taktik-analyse-video-100', 'taktik'),
            self::config('reaktion-anschlag-video-100', 'reaktion-clean-100'),
            self::config('sgs-lange-wiesel-100', '260826-clip-2-hju-100'),
        );

        $derived = (new SiblingIdRule())->derive($this->seed(), $html);

        self::assertCount(2, $derived);
        self::assertSame(MediaKind::Stream, $derived[0]->kind);
        self::assertSame('https://a.test/api/video/reaktion-anschlag-video-100.m3u8', $derived[0]->url);
        self::assertSame('https://a.test/assets/reaktion-clean-100~1920x1080?cb=1', $derived[0]->posterUrl);
        self::assertNull($derived[0]->precedingText);
        self::assertSame('https://a.test/api/video/sgs-lange-wiesel-100.m3u8', $derived[1]->url);
    }

    public function testAContextWithMoreThanFiveSiblingsIsAListNotTheArticle(): void
    {
        $configs = [self::config('taktik-analyse-video-100', 'taktik')];
        foreach (range(1, 6) as $n) {
            $configs[] = self::config('nav-entry-' . $n . '-100', 'nav-' . $n);
        }

        self::assertSame([], (new SiblingIdRule())->derive($this->seed(), self::page(...$configs)));
    }

    public function testASiblingThePageNamesInsideAUrlIsLeftToTheUrlSources(): void
    {
        $html = self::page(
            self::config('taktik-analyse-video-100', 'taktik'),
            self::config('gestern-clip-100', 'gestern'),
        ) . '<a href="https://a.test/api/video/gestern-clip-100.m3u8">yesterday</a>';

        self::assertSame([], (new SiblingIdRule())->derive($this->seed(), $html));
    }

    public function testASiblingWithoutAStillNearbyIsSkipped(): void
    {
        $html = self::page(
            self::config('taktik-analyse-video-100', 'taktik'),
            self::config('reaktion-anschlag-video-100'),
        );

        self::assertSame([], (new SiblingIdRule())->derive($this->seed(), $html));
    }

    public function testASiblingInAnotherContextIsSkipped(): void
    {
        $teaser = '{\\"teaser\\":{\\"type\\":\\"link\\",\\"content\\":\\"other-teaser-100\\",'
            . '\\"startImage\\":{\\"layouts\\":{\\"1x1\\":\\"https://a.test/assets/o~1x1\\"}}}}';
        $html = self::page(self::config('taktik-analyse-video-100', 'taktik'), $teaser);

        self::assertSame([], (new SiblingIdRule())->derive($this->seed(), $html));
    }

    public function testTheSeedsSuffixShapeIsRequired(): void
    {
        $html = self::page(
            self::config('taktik-analyse-video-100', 'taktik'),
            self::config('reaktion-anschlag-video', 'reaktion'),
        );

        self::assertSame([], (new SiblingIdRule())->derive($this->seed(), $html));
    }

    public function testAnEmbedSeedDerivesNothing(): void
    {
        $html = self::page(self::config('M1j_uRqKMKI', 'a'), self::config('Zx1_6F-nCaw', 'b'));
        $seed = $this->seed(MediaKind::Embed, 'https://www.youtube-nocookie.com/embed/M1j_uRqKMKI');

        self::assertSame([], (new SiblingIdRule())->derive($seed, $html));
    }

    public function testASeedWhoseStemIsNotAnIdDerivesNothing(): void
    {
        $html = self::page(self::config('main', 'a'), self::config('other', 'b'));
        $seed = $this->seed(MediaKind::Video, 'https://a.test/v/main.mp4');

        self::assertSame([], (new SiblingIdRule())->derive($seed, $html));
    }

    public function testASeedNamedOnlyInsideUrlsDerivesNothing(): void
    {
        $html = '<html><body><a href="/video/taktik-analyse-video-100.html">x</a>'
            . '<script>{"contentUrl":"https://a.test/api/video/taktik-analyse-video-100.m3u8"}</script></body></html>';

        self::assertSame([], (new SiblingIdRule())->derive($this->seed(), $html));
    }
}
