<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\AuthorBio\AuthorBioSeparator;
use App\Service\Reader\BoilerplateVerdict;
use App\Service\Reader\EdgeBoilerplateTrimmer;
use App\Service\Reader\LeadImageCandidate;
use App\Service\Reader\LeadingEngagementCleaner;
use App\Service\Reader\LeadingTitleRemover;
use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\InBodyEmbedRewriter;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\MediaMarkup;
use App\Service\Reader\Media\Teaser\TeaserPlayer;
use App\Service\Reader\Media\Teaser\TeaserPlayerInserter;
use App\Service\Reader\Media\Teaser\TeaserPlayerMarkup;
use App\Service\Reader\Media\PageMediaInserter;
use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use App\Service\Reader\Media\SubstackPosterLink;
use App\Service\Reader\MediaOnlyLede;
use App\Service\Reader\NavigationChromeTrimmer;
use App\Service\Reader\PageImageInventory;
use App\Service\Reader\PlayerChromeCleaner;
use App\Service\Reader\RecipeFacts\RecipeFactsCleaner;
use App\Service\Reader\DuplicateBlockCollapser;
use App\Service\Reader\ReaderBodyCleaner;
use App\Service\Reader\ReaderLeadImage;
use App\Service\Reader\Slideshow\SlideshowInserter;
use App\Service\Reader\Slideshow\SlideshowMarkup;
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
            new LeadingEngagementCleaner(),
            new EdgeBoilerplateTrimmer(new BoilerplateVerdict()),
            new ReaderLeadImage(),
            new InBodyEmbedRewriter(new EmbedProviders([new YouTubeEmbedProvider()]), $markup),
            new SubstackPosterLink(),
            new PlayerChromeCleaner(),
            new PageMediaInserter($markup),
            new SlideshowInserter(new SlideshowMarkup()),
            new RecipeFactsCleaner(),
            new TeaserPlayerInserter(new TeaserPlayerMarkup()),
            new MediaOnlyLede(),
            new DuplicateBlockCollapser(),
            new AuthorBioSeparator(),
        );
    }

    /** The Verge ships the dek and the lead image once per breakpoint; the reader must show each once (#963). */
    public function testCollapsesResponsiveDuplicateDekAndLeadImage(): void
    {
        $dek = 'Apple might recycle the name from Microsoft dual-screen device for its first folding iPhone.';
        $content = "<div><p>$dek</p></div><div><p>$dek</p></div>"
            . '<p><img src="https://x.test/stk071-apple-b.jpg?w=2400" alt="Apple event"></p>'
            . '<p><img src="https://x.test/stk071-apple-b.jpg?w=828" alt="Apple event"></p>'
            . '<p>' . self::PROSE . '</p>';

        $result = $this->cleaner->clean($content, [null], $this->noLead(), ArticleMedia::none());

        self::assertSame(1, substr_count($result, 'recycle the name from Microsoft'));
        self::assertSame(1, substr_count($result, '<img'));
    }

    /** The trailing "about the author" furniture is set apart in its own figure (#1000). */
    public function testSetsTheTrailingAuthorBioApartFromTheBody(): void
    {
        $content = '<div>'
            . '<div><p>' . self::PROSE . ' Erster.</p><p>' . self::PROSE . ' Zweiter.</p></div>'
            . '<div><p>' . self::PROSE . ' Zur Autorin.</p>'
            . '<p><a href="https://news.test/author/jane-doe/">View Bio</a></p></div>'
            . '</div>';

        $result = $this->cleaner->clean($content, [null], $this->noLead(), ArticleMedia::none());

        self::assertStringContainsString('<figure class="reader-author-bio">', $result);
    }

    private function noLead(): LeadImageCandidate
    {
        return new LeadImageCandidate(null, PageImageInventory::fromDocument(null));
    }

    public function testRebuildsAnOrphanTeaserThumbnailAsAnInlinePlayer(): void
    {
        $content = '<p>' . self::PROSE . '</p><p><img src="https://x.test/still.jpg"></p>';
        $teaser = new TeaserPlayer(
            MediaKind::Video,
            'https://x.test/clip.mp4',
            'https://x.test/still.jpg',
            'The headline',
            'https://x.test/related.html',
        );

        $result = $this->cleaner->clean($content, [null], $this->noLead(), ArticleMedia::none(), teasers: [$teaser]);

        self::assertStringContainsString('<figure class="reader-teaser">', $result);
        self::assertStringContainsString('<video', $result);
        self::assertStringContainsString('https://x.test/related.html', $result);
    }

    public function testDoesNotRebuildATeaserThePipelineAlreadyPlaced(): void
    {
        $content = '<p>' . self::PROSE . '</p><p><img src="https://x.test/still.jpg"></p>';
        $teaser = new TeaserPlayer(MediaKind::Video, 'https://x.test/clip.mp4', 'https://x.test/still.jpg', null, null);
        $media = new ArticleMedia([
            new MediaCandidate(MediaKind::Video, 'https://x.test/clip.mp4', 'https://x.test/still.jpg'),
        ]);

        $result = $this->cleaner->clean($content, [null], $this->noLead(), $media, teasers: [$teaser]);

        self::assertStringNotContainsString('reader-teaser', $result);
    }

    public function testRelaysARecipeFactBlockToTheReaderFigure(): void
    {
        $facts = '<div class="details-items">'
            . '<div class="detail-item"><span class="detail-item-icon"></span>'
            . '<span class="detail-item-label">Portionen</span>'
            . '<p class="detail-item-value">1</p>'
            . '<span class="detail-item-unit">Portionen</span></div></div>';
        $content = '<div><p>' . self::PROSE . '</p>' . $facts . '</div>';

        $result = $this->cleaner->clean($content, [null], $this->noLead(), ArticleMedia::none());

        self::assertStringContainsString('<figure class="reader-recipe-facts">', $result);
        self::assertStringContainsString('<dt>Portionen</dt><dd>1 Portionen</dd>', $result);
        self::assertStringNotContainsString('detail-item', $result);
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

    public function testDropsADuplicateTitleThatSatBehindAKicker(): void
    {
        $title = 'Schwedens Wohlfahrtsstaat nach 30 Jahren neoliberalem Experiment';
        $content = '<div><p>Kapitalismus</p><h2>' . $title . '</h2><p>' . self::PROSE . '</p></div>';

        $result = $this->cleaner->clean($content, [$title], $this->noLead(), ArticleMedia::none());

        self::assertStringNotContainsString('Kapitalismus', $result);
        self::assertStringNotContainsString($title, $result);
        self::assertStringContainsString('Fliesstext', $result);
    }

    public function testRemovesLeadingEngagementChromeInTheSamePass(): void
    {
        $content = '<div><p>1.251 Klicks</p><p>❤️️</p><p>' . self::PROSE . '</p></div>';

        $result = $this->cleaner->clean($content, [null], $this->noLead(), ArticleMedia::none());

        self::assertStringNotContainsString('Klicks', $result);
        self::assertStringNotContainsString('❤️', $result);
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

    /**
     * Substack 481600, the shape readability hands over for a paid video post:
     * a byline card (date, "Paid"), then #627's poster link, then the teaser.
     * The poster link is the reader's play overlay hook and must survive.
     */
    public function testKeepsTheGatedVideoPosterBelowASubstackBylineCard(): void
    {
        $poster = 'https://substackcdn.com/image/fetch/w_1200/https%3A%2F%2Fsubstack-video.s3.amazonaws.com%2Fp.png';
        $html = '<div><div><p>Sheldrake—Vernon Dialogue 103</p></div>'
            . '<div><p><time datetime="2026-08-25T10:44:42Z">Aug 25, 2026</time></p><p>∙ Paid</p></div>'
            . '<div><p><a href="https://x.substack.com/p/plants"><img src="' . $poster . '"'
            . ' alt="Video — open the original article to watch" width="1280" height="720"></a></p>'
            . '<p>' . self::PROSE . '</p></div></div>';
        $lead = new LeadImageCandidate($poster, PageImageInventory::fromDocument(null));

        $out = $this->cleaner->clean($html, [null], $lead, ArticleMedia::none());

        self::assertStringContainsString('alt="Video — open the original article to watch"', $out);
        self::assertSame(1, substr_count($out, '<img'), 'the poster is the only picture, no restored hero');
    }
}
