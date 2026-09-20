<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\PageMediaScanner;
use App\Service\Reader\Media\RawPage;
use App\Service\Reader\Media\Source\AttributeMediaSource;
use App\Service\Reader\Media\Source\JsonLdMediaSource;
use App\Service\Reader\Media\Source\MetaMediaSource;
use App\Service\Reader\Media\Source\PageEmbedSource;
use App\Service\Reader\Media\Source\ScriptEmbedSource;
use App\Service\Reader\Media\Source\SemanticMediaSource;
use App\Service\Reader\Media\Source\YouTubeIdAttributeSource;
use App\Service\Reader\Media\Source\ZdfPlayerConfigSource;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PageMediaScannerWiringTest extends KernelTestCase
{
    use ScansWithTheWiredSources;

    public function testTheTaggedSourcesAreCollected(): void
    {
        $html = '<html lang="en"><body><iframe src="https://www.youtube.com/embed/M1j_uRqKMKI"></iframe></body></html>';
        $media = $this->scan($html, 'https://example.test/article');

        self::assertFalse($media->isEmpty());
        self::assertSame('https://www.youtube-nocookie.com/embed/M1j_uRqKMKI', $media->candidates[0]->url);
    }

    public function testAnIdDeclaredInDataVideoIdIsCollectedThroughTheTag(): void
    {
        $html = '<html lang="en"><body><div data-component="youtube-atom" data-video-id="M1j_uRqKMKI"></div>'
            . '</body></html>';
        $media = $this->scan($html, 'https://example.test/article');

        self::assertSame('https://www.youtube-nocookie.com/embed/M1j_uRqKMKI', $media->candidates[0]->url);
    }

    public function testABlankPageYieldsNoMedia(): void
    {
        self::assertTrue($this->scan('', 'https://example.test/article')->isEmpty());
    }

    /** @return iterable<string, array{string}> */
    public static function mediaFixtureProvider(): iterable
    {
        foreach (glob(__DIR__ . '/../../../Fixtures/reader/media/*.html') ?: [] as $path) {
            yield basename($path) => [$path];
        }
    }

    /** The sources share one document, so a source that mutates it changes what every later source sees. */
    #[DataProvider('mediaFixtureProvider')]
    public function testNoSourceChangesTheSharedDocument(string $fixturePath): void
    {
        $page = RawPage::parse((string) file_get_contents($fixturePath), 'https://www.zdfheute.de/article-100.html');
        $before = $page->document->saveHtml();

        $this->scanner()->scan($page);

        self::assertSame($before, $page->document->saveHtml());
    }

    /**
     * The order IS the behaviour: the first source to name a URL sets its poster and place, and every
     * lower one only fills gaps. A new source must fail here until someone places it on purpose (#756).
     */
    public function testTheSourcesRunInTheirDeclaredOrder(): void
    {
        $sources = new \ReflectionProperty(PageMediaScanner::class, 'sources')->getValue($this->scanner());
        self::assertIsIterable($sources);
        $ordered = [];
        foreach ($sources as $source) {
            self::assertIsObject($source);
            $ordered[] = $source::class;
        }

        self::assertSame([
            JsonLdMediaSource::class,
            MetaMediaSource::class,
            ZdfPlayerConfigSource::class,
            PageEmbedSource::class,
            SemanticMediaSource::class,
            AttributeMediaSource::class,
            YouTubeIdAttributeSource::class,
            ScriptEmbedSource::class,
        ], $ordered);
    }

    /**
     * Entry 491912: two <video> elements, each with its own poster. The attribute scan sees both files
     * too but knows only the page's og:image, so it must run AFTER the semantic source or one video
     * shows the other's still (#756).
     */
    public function testEachVideoKeepsItsOwnPosterOverThePageImage(): void
    {
        $html = '<html lang="en"><head><meta property="og:image" content="https://x.test/page-image.jpg"></head><body>'
            . '<video poster="https://x.test/first-still.jpg" src="https://x.test/first.mp4"></video>'
            . '<video poster="https://x.test/second-still.jpg" src="https://x.test/second.mp4"></video>'
            . '</body></html>';

        $media = $this->scan($html, 'https://x.test/article');

        $posters = [];
        foreach ($media->candidates as $candidate) {
            $posters[$candidate->url] = $candidate->posterUrl;
        }
        self::assertSame([
            'https://x.test/first.mp4' => 'https://x.test/first-still.jpg',
            'https://x.test/second.mp4' => 'https://x.test/second-still.jpg',
        ], $posters);
    }
}
