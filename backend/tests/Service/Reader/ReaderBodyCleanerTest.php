<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\EdgeBoilerplateTrimmer;
use App\Service\Reader\LeadImageCandidate;
use App\Service\Reader\LeadingTitleRemover;
use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\InBodyEmbedRewriter;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\MediaMarkup;
use App\Service\Reader\Media\PageMediaInserter;
use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use App\Service\Reader\Media\SubstackPosterLink;
use App\Service\Reader\NavigationChromeTrimmer;
use App\Service\Reader\PageImageInventory;
use App\Service\Reader\ReaderBodyCleaner;
use App\Service\Reader\ReaderLeadImage;
use PHPUnit\Framework\TestCase;

final class ReaderBodyCleanerTest extends TestCase
{
    private const string PROSE =
        'Ein ausreichend langer Absatz mit echtem Fliesstext, der die Schwelle '
        . 'fuer einen substantiellen Absatz sicher ueberschreitet und daher als '
        . 'echter Artikelinhalt zaehlt und nicht als Randblock behandelt wird.';

    private ReaderBodyCleaner $cleaner;

    protected function setUp(): void
    {
        $markup = new MediaMarkup();
        $this->cleaner = new ReaderBodyCleaner(
            new NavigationChromeTrimmer(),
            new LeadingTitleRemover(),
            new EdgeBoilerplateTrimmer(),
            new ReaderLeadImage(),
            new InBodyEmbedRewriter(new EmbedProviders([new YouTubeEmbedProvider()]), $markup),
            new SubstackPosterLink(),
            new PageMediaInserter($markup),
        );
    }

    private function noLead(): LeadImageCandidate
    {
        return new LeadImageCandidate(null, PageImageInventory::fromDocument(null));
    }

    public function testStripsALeadingNavigationChromeRegionInTheSamePass(): void
    {
        $header = '<div class="site-header"><nav><a href="/a">Editorial</a>'
            . '<a href="/b">Blog</a><a href="/c">Debate</a><a href="/d">About</a></nav></div>';
        $content = '<div>' . $header . '<main><p>' . self::PROSE . '</p></main></div>';

        $result = $this->cleaner->clean($content, [null], $this->noLead(), ArticleMedia::none());

        self::assertStringNotContainsString('site-header', $result);
        self::assertStringContainsString('Fliesstext', $result);
    }

    public function testDropsTheLeadingDuplicateHeadingInOnePass(): void
    {
        $content = '<div><h2>My Article</h2><p>' . self::PROSE . '</p></div>';

        $result = $this->cleaner->clean($content, ['My Article'], $this->noLead(), ArticleMedia::none());

        self::assertStringNotContainsString('<h2>', $result);
        self::assertStringContainsString('Fliesstext', $result);
    }

    public function testTrimsTrailingEdgeBoilerplateInTheSamePass(): void
    {
        $grid = '<div class="jp-relatedposts"><a href="/a">A</a><a href="/b">B</a>'
            . '<a href="/c">C</a><a href="/d">D</a></div>';
        $content = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $grid . '</div>';

        $result = $this->cleaner->clean($content, [null], $this->noLead(), ArticleMedia::none());

        self::assertStringNotContainsString('jp-relatedposts', $result);
        self::assertStringContainsString('Fliesstext', $result);
    }

    public function testRemovesTheDuplicateHeadingAndTheTrailingBoilerplateTogether(): void
    {
        $grid = '<div class="jp-relatedposts"><a href="/a">A</a><a href="/b">B</a>'
            . '<a href="/c">C</a><a href="/d">D</a></div>';
        $content = '<div><h2>My Article</h2><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . '<p>' . self::PROSE . '</p>' . $grid . '</div>';

        $result = $this->cleaner->clean($content, ['My Article'], $this->noLead(), ArticleMedia::none());

        self::assertStringNotContainsString('<h2>', $result);
        self::assertStringNotContainsString('jp-relatedposts', $result);
        self::assertStringContainsString('Fliesstext', $result);
    }

    public function testReturnsBlankInputUnchangedWithoutParsing(): void
    {
        // Readability output is always non-empty in the pipeline, but a body that
        // cannot be parsed must fall through untouched rather than crash the pass.
        self::assertSame('   ', $this->cleaner->clean('   ', ['My Article'], $this->noLead(), ArticleMedia::none()));
    }

    public function testRestoresTheLeadIntoATextOnlyBodyInTheSharedWindow(): void
    {
        $content = '<div><p>' . self::PROSE . '</p></div>';
        $candidate = new LeadImageCandidate(
            'https://cdn.test/hero.jpg',
            PageImageInventory::fromDocument(null),
        );

        $result = $this->cleaner->clean($content, [null], $candidate, ArticleMedia::none());

        self::assertStringContainsString('<img src="https://cdn.test/hero.jpg"', $result);
        self::assertStringContainsString('Fliesstext', $result);
    }

    public function testRewritesAnInBodyEmbedAndKeepsItsPosition(): void
    {
        $html = '<h3>One</h3><div><iframe src="https://www.youtube.com/embed/aaaaaaaaaaa"></iframe></div>'
            . '<p>' . self::PROSE . '</p>';

        $out = $this->cleaner->clean($html, [null, null], $this->noLead(), ArticleMedia::none());

        self::assertStringContainsString('youtube-nocookie.com/embed/aaaaaaaaaaa', $out);
        self::assertStringNotContainsString('<iframe', $out);
    }

    /**
     * A discovered embed is dropped when the body recovered its own, so the same
     * video never appears twice.
     */
    public function testSuppressesDiscoveredEmbedsWhenTheBodyHadItsOwn(): void
    {
        $html = '<div><iframe src="https://www.youtube.com/embed/aaaaaaaaaaa"></iframe></div>'
            . '<p>' . self::PROSE . '</p>';
        $discovered = new ArticleMedia([
            new MediaCandidate(MediaKind::Embed, 'https://www.youtube-nocookie.com/embed/bbbbbbbbbbb', null, 'Watch'),
        ]);

        $out = $this->cleaner->clean($html, [null, null], $this->noLead(), $discovered);

        self::assertStringContainsString('aaaaaaaaaaa', $out);
        self::assertStringNotContainsString('bbbbbbbbbbb', $out);
    }

    /** Audio is not an embed, so the suppression must not reach it. */
    public function testKeepsDiscoveredAudioEvenWhenTheBodyHadAnEmbed(): void
    {
        $html = '<div><iframe src="https://www.youtube.com/embed/aaaaaaaaaaa"></iframe></div>'
            . '<p>' . self::PROSE . '</p>';
        $discovered = new ArticleMedia([new MediaCandidate(MediaKind::Audio, 'https://x.test/a.mp3')]);

        $out = $this->cleaner->clean($html, [null, null], $this->noLead(), $discovered);

        self::assertStringContainsString('a.mp3', $out);
    }

    /**
     * tagesschau 491512: a body img shares the video poster's path UUID, a
     * different rendition. The video reconciles into that img's position, no
     * duplicate remains, and it is not also prepended at the top; an
     * accompanying audio candidate with no matching body img is top-placed.
     */
    public function testReconcilesARecoveredVideoIntoItsMatchingBodyImage(): void
    {
        $poster = 'https://media.tagesschau.de/image/7ad74081-1234-5678-9abc-def012345678/A/16x9-1920/p.jpg';
        $bodyImg = 'https://media.tagesschau.de/image/7ad74081-1234-5678-9abc-def012345678/B/16x9-big/t.jpg';
        $html = '<div><p>' . self::PROSE . '</p><figure><img src="' . $bodyImg . '" alt=""></figure></div>';
        $discovered = new ArticleMedia([
            new MediaCandidate(MediaKind::Video, 'https://x.test/v.mp4', $poster),
            new MediaCandidate(MediaKind::Audio, 'https://x.test/a.mp3'),
        ]);

        $out = $this->cleaner->clean($html, [null], $this->noLead(), $discovered);

        self::assertSame(1, substr_count($out, '<video'));
        self::assertStringNotContainsString('<img', $out);
        self::assertLessThan(strpos($out, '<video'), strpos($out, '<audio'), 'audio has no match, so it leads');
        self::assertGreaterThan(
            strpos($out, 'Fliesstext'),
            strpos($out, '<video'),
            'the video stays in place, not at top',
        );
    }

    /**
     * heise 487576: an embed poster and the hero are the same picture from
     * different CDNs, so identity cannot match them — the embed is top-placed
     * and the hero must be suppressed rather than stacking a duplicate above it.
     */
    public function testSuppressesTheHeroWhenARecoveredEmbedIsTopPlaced(): void
    {
        $html = '<div><p>' . self::PROSE . '</p></div>';
        $lead = new LeadImageCandidate(
            'https://heise.cloudimg.example/thumb.jpg',
            PageImageInventory::fromDocument(null),
        );
        $discovered = new ArticleMedia([
            new MediaCandidate(
                MediaKind::Embed,
                'https://www.youtube-nocookie.com/embed/ccccccccccc',
                'https://i.ytimg.example/hqdefault.jpg',
                'Watch',
            ),
        ]);

        $out = $this->cleaner->clean($html, [null], $lead, $discovered);

        self::assertStringNotContainsString('heise.cloudimg.example', $out);
        self::assertStringContainsString('i.ytimg.example/hqdefault.jpg', $out);
    }

    /**
     * tagesschau 491912 mix: video1 reconciles into its matching body img,
     * video2 has no match and is top-placed, and an unrelated map img is
     * left untouched.
     */
    public function testMixesReconciledAndTopPlacedVideosInTheSamePass(): void
    {
        $video1Poster = 'https://media.tagesschau.de/image/80085f9c-1234-5678-9abc-def012345678/A/16x9-1920/p.jpg';
        $video1Body = 'https://media.tagesschau.de/image/80085f9c-1234-5678-9abc-def012345678/B/16x9-big/t.jpg';
        $video2Poster = 'https://media.tagesschau.de/image/58e272fd-1234-5678-9abc-def012345678/A/16x9-1920/p.jpg';
        $mapImg = 'https://media.tagesschau.de/image/deadbeef-0000-0000-0000-000000000000/A/map.jpg';
        $html = '<div><p>' . self::PROSE . '</p>'
            . '<figure><img src="' . $video1Body . '" alt=""></figure>'
            . '<figure><img src="' . $mapImg . '" alt=""></figure></div>';
        $discovered = new ArticleMedia([
            new MediaCandidate(MediaKind::Video, 'https://x.test/v1.mp4', $video1Poster),
            new MediaCandidate(MediaKind::Video, 'https://x.test/v2.mp4', $video2Poster),
        ]);

        $out = $this->cleaner->clean($html, [null], $this->noLead(), $discovered);

        self::assertSame(2, substr_count($out, '<video'));
        self::assertSame(1, substr_count($out, '<img'));
        self::assertStringContainsString($mapImg, $out);
        self::assertLessThan(strpos($out, 'v1.mp4'), strpos($out, 'v2.mp4'), 'the unmatched video leads');
    }

    /** The <a> guard: a body img inside an anchor is not reconciled even when its asset matches. */
    public function testDoesNotReconcileABodyImageInsideAnAnchor(): void
    {
        $poster = 'https://media.tagesschau.de/image/7ad74081-1234-5678-9abc-def012345678/A/16x9-1920/p.jpg';
        $bodyImg = 'https://media.tagesschau.de/image/7ad74081-1234-5678-9abc-def012345678/B/16x9-big/t.jpg';
        $html = '<div><p>' . self::PROSE . '</p>'
            . '<a href="https://x.test/story"><img src="' . $bodyImg . '" alt=""></a></div>';
        $discovered = new ArticleMedia([
            new MediaCandidate(MediaKind::Video, 'https://x.test/v.mp4', $poster),
        ]);

        $out = $this->cleaner->clean($html, [null], $this->noLead(), $discovered);

        self::assertStringContainsString('<img', $out);
        self::assertStringContainsString('<video', $out);
    }
}
