<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Reader\BodyCleaning\BodyCleaningStep\InBodyEmbedRewriter;
use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;
use App\Service\Reader\Media\EmbedProvider\SoundCloudEmbedProvider;
use App\Service\Reader\Media\EmbedProvider\YouTubeEmbedProvider;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaMarkup;
use App\Service\Reader\Media\Model\ArticleMediaModel;
use App\Service\Reader\Media\Model\MediaCandidateModel;
use App\Service\Reader\Media\Model\MediaKind;
use App\Tests\Support\BodyCleaningInputs;
use App\Tests\Support\BodyCleaningPasses;
use App\Tests\Support\ParsesHtml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InBodyEmbedRewriterTest extends TestCase
{
    use ParsesHtml;

    private InBodyEmbedRewriter $rewriter;

    protected function setUp(): void
    {
        $this->rewriter = new InBodyEmbedRewriter(
            new EmbedProviders([new YouTubeEmbedProvider(), new SoundCloudEmbedProvider()]),
            new MediaMarkup(),
        );
    }

    private function rewrite(string $html): string
    {
        $pass = BodyCleaningPasses::over($this->document($html));
        $this->rewriter->cleanIn($pass);

        return $pass->document->saveHtml();
    }

    /** The OZORA shape: a heading, then the embed, ten times over. */
    public function testKeepsEachEmbedAtItsHeadingPosition(): void
    {
        $html = '<body><h3>One</h3><div><iframe src="https://www.youtube.com/embed/aaaaaaaaaaa?si=x"></iframe></div>'
            . '<h3>Two</h3><div><iframe src="https://www.youtube.com/embed/bbbbbbbbbbb"></iframe></div></body>';

        $out = $this->rewrite($html);

        self::assertStringContainsString('<h3>One</h3>', $out);
        self::assertStringContainsString('youtube-nocookie.com/embed/aaaaaaaaaaa', $out);
        self::assertStringContainsString('youtube-nocookie.com/embed/bbbbbbbbbbb', $out);
        self::assertLessThan(
            strpos($out, 'bbbbbbbbbbb'),
            strpos($out, 'aaaaaaaaaaa'),
            'embeds must keep source order'
        );
        self::assertStringNotContainsString('<iframe', $out);
        self::assertStringNotContainsString('si=x', $out);
    }

    public function testAYouTubeEmbedBecomesAPosterLink(): void
    {
        $out = $this->rewrite('<body><iframe src="https://www.youtube.com/embed/aaaaaaaaaaa"></iframe></body>');

        self::assertStringContainsString('href="https://www.youtube-nocookie.com/embed/aaaaaaaaaaa"', $out);
        self::assertStringContainsString('i.ytimg.com/vi/aaaaaaaaaaa/hqdefault.jpg', $out);
    }

    /** No cheap poster, so the link carries text a reader can act on. */
    public function testASoundCloudEmbedBecomesATextLink(): void
    {
        $out = $this->rewrite('<body><iframe src="https://w.soundcloud.com/player/'
            . '?url=https%3A//api.soundcloud.com/tracks/2370150908&amp;auto_play=true"></iframe></body>');

        self::assertStringContainsString('Listen on SoundCloud', $out);
        self::assertStringNotContainsString('auto_play', $out);
    }

    public function testAnUnknownIframeIsLeftForTheSanitizer(): void
    {
        $html = '<body><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-1"></iframe></body>';

        self::assertStringContainsString('googletagmanager', $this->rewrite($html));
    }

    public function testRecordsARecoveredEmbedSoTheDiscoveredEmbedsStandDown(): void
    {
        $discovered = $this->discoveredEmbed();
        $none = new BodyCleaningPass(
            $this->document('<body><p>text</p></body>'),
            BodyCleaningInputs::withMedia($discovered),
        );
        $one = new BodyCleaningPass(
            $this->document('<body><iframe src="https://youtu.be/aaaaaaaaaaa"></iframe></body>'),
            BodyCleaningInputs::withMedia($discovered),
        );

        $this->rewriter->cleanIn($none);
        $this->rewriter->cleanIn($one);

        self::assertSame($discovered, $none->discoveredMedia());
        self::assertTrue($one->discoveredMedia()->isEmpty());
    }

    /** Do not reuse #627's alt text: its CSS paints a play badge on that string. */
    public function testDoesNotReuseTheSubstackPlaceholderAltText(): void
    {
        $out = $this->rewrite('<body><iframe src="https://www.youtube.com/embed/aaaaaaaaaaa"></iframe></body>');

        self::assertStringNotContainsString('Video — open the original article to watch', $out);
    }

    /** @return iterable<string, array{0: string}> */
    public static function iframeOrderProvider(): iterable
    {
        yield 'unknown first' => [
            '<iframe src="https://www.googletagmanager.com/ns.html?id=GTM-1"></iframe>'
            . '<iframe src="https://youtu.be/aaaaaaaaaaa"></iframe>',
        ];
        yield 'unknown last' => [
            '<iframe src="https://youtu.be/aaaaaaaaaaa"></iframe>'
            . '<iframe src="https://www.googletagmanager.com/ns.html?id=GTM-1"></iframe>',
        ];
    }

    #[DataProvider('iframeOrderProvider')]
    public function testRecordsRecoveryWhenOneOfSeveralIframesIsUnknown(string $iframes): void
    {
        $pass = new BodyCleaningPass(
            $this->document('<body>' . $iframes . '</body>'),
            BodyCleaningInputs::withMedia($this->discoveredEmbed()),
        );

        $this->rewriter->cleanIn($pass);

        self::assertTrue($pass->discoveredMedia()->isEmpty());
    }

    private function discoveredEmbed(): ArticleMediaModel
    {
        return new ArticleMediaModel([
            new MediaCandidateModel(MediaKind::Embed, 'https://www.youtube-nocookie.com/embed/bbbbbbbbbbb'),
        ]);
    }
}
