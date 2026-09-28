<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\BodyCleaning\BodyCleaningStep\AuthorBioSeparator;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\BodyCleaningStepInterface;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\PageMediaPlacement;
use App\Service\Reader\BoilerplateVerdict;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\DuplicateBlockCollapser;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\EdgeBoilerplateTrimmer;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\FeedDimensionStamper;
use App\Service\Reader\LeadImageCandidate;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\LeadingEngagementCleaner;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\LeadingTitleRemover;
use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\InBodyEmbedRewriter;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\MediaMarkup;
use App\Service\Reader\Media\PageMediaInserter;
use App\Service\Reader\Media\EmbedProvider\SpotifyEmbedProvider;
use App\Service\Reader\Media\EmbedProvider\YouTubeEmbedProvider;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\SubstackPosterLink;
use App\Service\Reader\Media\Teaser\TeaserPlayer;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\TeaserPlayerInserter;
use App\Service\Reader\Media\Teaser\TeaserPlayerMarkup;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\MediaOnlyLede;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\NavigationChromeTrimmer;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\PlayerChromeCleaner;
use App\Service\Reader\ReaderBodyCleaner;
use App\Service\Reader\ReaderLeadImage;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\RecipeFactsCleaner;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\SlideshowInserter;
use App\Service\Reader\Slideshow\SlideshowMarkup;
use App\Tests\Support\BodyCleaningInputs;
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
        $this->cleaner = new ReaderBodyCleaner(
            self::steps(new EmbedProviders([new YouTubeEmbedProvider(), new SpotifyEmbedProvider()])),
        );
    }

    /** @return list<BodyCleaningStepInterface> the steps in the order services.yaml wires them */
    public static function steps(EmbedProviders $embedProviders): array
    {
        $markup = new MediaMarkup();

        return [
            new InBodyEmbedRewriter($embedProviders, $markup),
            new SubstackPosterLink(),
            new PlayerChromeCleaner(),
            new NavigationChromeTrimmer(),
            new LeadingEngagementCleaner(),
            new LeadingTitleRemover(),
            new EdgeBoilerplateTrimmer(new BoilerplateVerdict()),
            new SlideshowInserter(new SlideshowMarkup()),
            new RecipeFactsCleaner(),
            new DuplicateBlockCollapser($embedProviders),
            new PageMediaPlacement(new PageMediaInserter($markup), new ReaderLeadImage()),
            new TeaserPlayerInserter(new TeaserPlayerMarkup()),
            new MediaOnlyLede(),
            new AuthorBioSeparator(),
            new FeedDimensionStamper(),
        ];
    }

    /**
     * The Verge ships the dek once per breakpoint; the reader collapses it, and keeps the lead image untouched
     * (#963, #1088).
     */
    public function testCollapsesTheResponsiveDuplicateDek(): void
    {
        $dek = 'Apple might recycle the name from Microsoft dual-screen device for its first folding iPhone.';
        $content = "<div><p>$dek</p></div><div><p>$dek</p></div>"
            . '<p><img src="https://x.test/stk071-apple-b.jpg?w=2400" alt="Apple event"></p>'
            . '<p>' . self::PROSE . '</p>';

        $result = $this->cleaner->clean($content, BodyCleaningInputs::nothingKnown());

        self::assertSame(1, substr_count($result, 'recycle the name from Microsoft'));
    }

    /** The trailing "about the author" furniture is set apart in its own figure (#1000). */
    public function testSetsTheTrailingAuthorBioApartFromTheBody(): void
    {
        $content = '<div>'
            . '<div><p>' . self::PROSE . ' Erster.</p><p>' . self::PROSE . ' Zweiter.</p></div>'
            . '<div><p>' . self::PROSE . ' Zur Autorin.</p>'
            . '<p><a href="https://news.test/author/jane-doe/">View Bio</a></p></div>'
            . '</div>';

        $result = $this->cleaner->clean($content, BodyCleaningInputs::nothingKnown());

        self::assertStringContainsString('<figure class="reader-author-bio">', $result);
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

        $result = $this->cleaner->clean($content, BodyCleaningInputs::withTeasers([$teaser], ArticleMedia::none()));

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

        $result = $this->cleaner->clean($content, BodyCleaningInputs::withTeasers([$teaser], $media));

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

        $result = $this->cleaner->clean($content, BodyCleaningInputs::nothingKnown());

        self::assertStringContainsString('<figure class="reader-recipe-facts">', $result);
        self::assertStringContainsString('<dt>Portionen</dt><dd>1 Portionen</dd>', $result);
        self::assertStringNotContainsString('detail-item', $result);
    }

    public function testStripsALeadingNavigationChromeRegionInTheSamePass(): void
    {
        $header = '<div class="site-header"><nav><a href="/a">Editorial</a>'
            . '<a href="/b">Blog</a><a href="/c">Debate</a><a href="/d">About</a></nav></div>';
        $content = '<div>' . $header . '<main><p>' . self::PROSE . '</p></main></div>';

        $result = $this->cleaner->clean($content, BodyCleaningInputs::nothingKnown());

        self::assertStringNotContainsString('site-header', $result);
        self::assertStringContainsString('Fliesstext', $result);
    }

    public function testDropsTheLeadingDuplicateHeadingInOnePass(): void
    {
        $content = '<div><h2>My Article</h2><p>' . self::PROSE . '</p></div>';

        $result = $this->cleaner->clean($content, BodyCleaningInputs::withTitles(['My Article']));

        self::assertStringNotContainsString('<h2>', $result);
        self::assertStringContainsString('Fliesstext', $result);
    }

    public function testDropsADuplicateTitleThatSatBehindAKicker(): void
    {
        $title = 'Schwedens Wohlfahrtsstaat nach 30 Jahren neoliberalem Experiment';
        $content = '<div><p>Kapitalismus</p><h2>' . $title . '</h2><p>' . self::PROSE . '</p></div>';

        $result = $this->cleaner->clean($content, BodyCleaningInputs::withTitles([$title]));

        self::assertStringNotContainsString('Kapitalismus', $result);
        self::assertStringNotContainsString($title, $result);
        self::assertStringContainsString('Fliesstext', $result);
    }

    public function testRemovesLeadingEngagementChromeInTheSamePass(): void
    {
        $content = '<div><p>1.251 Klicks</p><p>❤️️</p><p>' . self::PROSE . '</p></div>';

        $result = $this->cleaner->clean($content, BodyCleaningInputs::nothingKnown());

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

        $result = $this->cleaner->clean($content, BodyCleaningInputs::nothingKnown());

        self::assertStringNotContainsString('jp-relatedposts', $result);
        self::assertStringContainsString('Fliesstext', $result);
    }

    public function testRemovesTheDuplicateHeadingAndTheTrailingBoilerplateTogether(): void
    {
        $grid = '<div class="jp-relatedposts"><a href="/a">A</a><a href="/b">B</a>'
            . '<a href="/c">C</a><a href="/d">D</a></div>';
        $content = '<div><h2>My Article</h2><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . '<p>' . self::PROSE . '</p>' . $grid . '</div>';

        $result = $this->cleaner->clean($content, BodyCleaningInputs::withTitles(['My Article']));

        self::assertStringNotContainsString('<h2>', $result);
        self::assertStringNotContainsString('jp-relatedposts', $result);
        self::assertStringContainsString('Fliesstext', $result);
    }

    public function testReturnsBlankInputUnchangedWithoutParsing(): void
    {
        // Readability output is always non-empty in the pipeline, but a body that
        // cannot be parsed must fall through untouched rather than crash the pass.
        self::assertSame('   ', $this->cleaner->clean('   ', BodyCleaningInputs::withTitles(['My Article'])));
    }

    public function testRestoresTheLeadIntoATextOnlyBodyInTheSharedWindow(): void
    {
        $content = '<div><p>' . self::PROSE . '</p></div>';
        $candidate = new LeadImageCandidate('https://cdn.test/hero.jpg', BodyCleaningInputs::pageDrawingNothing());

        $result = $this->cleaner->clean($content, BodyCleaningInputs::withLeadImage($candidate));

        self::assertStringContainsString('<img src="https://cdn.test/hero.jpg"', $result);
        self::assertStringContainsString('Fliesstext', $result);
    }

    public function testRewritesAnInBodyEmbedAndKeepsItsPosition(): void
    {
        $html = '<h3>One</h3><div><iframe src="https://www.youtube.com/embed/aaaaaaaaaaa"></iframe></div>'
            . '<p>' . self::PROSE . '</p>';

        $out = $this->cleaner->clean($html, BodyCleaningInputs::nothingKnown());

        self::assertStringContainsString('youtube-nocookie.com/embed/aaaaaaaaaaa', $out);
        self::assertStringNotContainsString('<iframe', $out);
    }

    /** One video per section recovers a poster per embed, all `hqdefault.jpg`; every embed stays (#1051). */
    public function testKeepsEveryInBodyEmbedWhenTheirPostersShareAStem(): void
    {
        $html = '<h4>One</h4><div><iframe src="https://www.youtube.com/embed/aaaaaaaaaaa"></iframe></div>'
            . '<h4>Two</h4><div><iframe src="https://www.youtube.com/embed/bbbbbbbbbbb"></iframe></div>'
            . '<h4>Three</h4><div><iframe src="https://www.youtube.com/embed/ccccccccccc"></iframe></div>'
            . '<p>' . self::PROSE . '</p>';

        $out = $this->cleaner->clean($html, BodyCleaningInputs::nothingKnown());

        self::assertStringContainsString('embed/aaaaaaaaaaa', $out);
        self::assertStringContainsString('embed/bbbbbbbbbbb', $out);
        self::assertStringContainsString('embed/ccccccccccc', $out);
    }

    /** A Spotify player embedded in the body survives as an embed link the client upgrades (#1053). */
    public function testRewritesAnInBodySpotifyEmbed(): void
    {
        $html = '<p>' . self::PROSE . '</p><h3>Playlist</h3>'
            . '<div><iframe src="https://open.spotify.com/embed/playlist/27uRYdAHvcKADidfnR8BN4"></iframe></div>';

        $out = $this->cleaner->clean($html, BodyCleaningInputs::nothingKnown());

        self::assertStringContainsString('open.spotify.com/embed/playlist/27uRYdAHvcKADidfnR8BN4', $out);
        self::assertStringNotContainsString('<iframe', $out);
    }

    /** A discovered embed is dropped when the body recovered its own, so the same video never appears twice. */
    public function testSuppressesDiscoveredEmbedsWhenTheBodyHadItsOwn(): void
    {
        $html = '<div><iframe src="https://www.youtube.com/embed/aaaaaaaaaaa"></iframe></div>'
            . '<p>' . self::PROSE . '</p>';
        $discovered = new ArticleMedia([
            new MediaCandidate(MediaKind::Embed, 'https://www.youtube-nocookie.com/embed/bbbbbbbbbbb', null, 'Watch'),
        ]);

        $out = $this->cleaner->clean($html, BodyCleaningInputs::withMedia($discovered));

        self::assertStringContainsString('aaaaaaaaaaa', $out);
        self::assertStringNotContainsString('bbbbbbbbbbb', $out);
    }

    /** Audio is not an embed, so the suppression must not reach it. */
    public function testKeepsDiscoveredAudioEvenWhenTheBodyHadAnEmbed(): void
    {
        $html = '<div><iframe src="https://www.youtube.com/embed/aaaaaaaaaaa"></iframe></div>'
            . '<p>' . self::PROSE . '</p>';
        $discovered = new ArticleMedia([new MediaCandidate(MediaKind::Audio, 'https://x.test/a.mp3')]);

        $out = $this->cleaner->clean($html, BodyCleaningInputs::withMedia($discovered));

        self::assertStringContainsString('a.mp3', $out);
    }

    /**
     * tagesschau 491512: the video reconciles into the body img that shares its poster's path UUID, in place and
     * once; an audio candidate with no matching img is top-placed.
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

        $out = $this->cleaner->clean($html, BodyCleaningInputs::withMedia($discovered));

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
     * heise 487576: the embed poster and the hero are one picture on two CDNs, so identity cannot match them;
     * the embed is top-placed and the hero is suppressed rather than stacked above it.
     */
    public function testSuppressesTheHeroWhenARecoveredEmbedIsTopPlaced(): void
    {
        $html = '<div><p>' . self::PROSE . '</p></div>';
        $lead = new LeadImageCandidate(
            'https://heise.cloudimg.example/thumb.jpg',
            BodyCleaningInputs::pageDrawingNothing(),
        );
        $discovered = new ArticleMedia([
            new MediaCandidate(
                MediaKind::Embed,
                'https://www.youtube-nocookie.com/embed/ccccccccccc',
                'https://i.ytimg.example/hqdefault.jpg',
                'Watch',
            ),
        ]);

        $out = $this->cleaner->clean($html, BodyCleaningInputs::withLeadImageAndMedia($lead, $discovered));

        self::assertStringNotContainsString('heise.cloudimg.example', $out);
        self::assertStringContainsString('i.ytimg.example/hqdefault.jpg', $out);
    }

    /**
     * tagesschau 491912: video1 reconciles into its matching body img, video2 has no match and is top-placed, and
     * an unrelated map img is left untouched.
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

        $out = $this->cleaner->clean($html, BodyCleaningInputs::withMedia($discovered));

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

        $out = $this->cleaner->clean($html, BodyCleaningInputs::withMedia($discovered));

        self::assertStringContainsString('<img', $out);
        self::assertStringContainsString('<video', $out);
    }

    /**
     * Substack 481600, a paid video post: a byline card ("Paid"), then #627's poster link, then the teaser. The
     * poster link is the reader's play overlay hook and must survive.
     */
    public function testKeepsTheGatedVideoPosterBelowASubstackBylineCard(): void
    {
        $poster = 'https://substackcdn.com/image/fetch/w_1200/https%3A%2F%2Fsubstack-video.s3.amazonaws.com%2Fp.png';
        $html = '<div><div><p>Sheldrake—Vernon Dialogue 103</p></div>'
            . '<div><p><time datetime="2026-08-25T10:44:42Z">Aug 25, 2026</time></p><p>∙ Paid</p></div>'
            . '<div><p><a href="https://x.substack.com/p/plants"><img src="' . $poster . '"'
            . ' alt="Video — open the original article to watch" width="1280" height="720"></a></p>'
            . '<p>' . self::PROSE . '</p></div></div>';
        $lead = new LeadImageCandidate($poster, BodyCleaningInputs::pageDrawingNothing());

        $out = $this->cleaner->clean($html, BodyCleaningInputs::withLeadImage($lead));

        self::assertStringContainsString('alt="Video — open the original article to watch"', $out);
        self::assertSame(1, substr_count($out, '<img'), 'the poster is the only picture, no restored hero');
    }

    public function testLinksABareSubstackPoster(): void
    {
        $content = '<p><img src="https://substackcdn.com/image/youtube/w_728/aaaaaaaaaaa"></p>'
            . '<p>' . self::PROSE . '</p>';

        $out = $this->cleaner->clean($content, BodyCleaningInputs::nothingKnown());

        self::assertStringContainsString('href="https://www.youtube-nocookie.com/embed/aaaaaaaaaaa"', $out);
    }

    /**
     * A masthead menu long enough that its text exceeds LeadingEngagementCleaner's own
     * navigation-label threshold: only NavigationChromeTrimmer's landmark-based rule removes it.
     */
    public function testStripsALeadingNavLandmarkTooLongForTheEngagementCleanerToCatch(): void
    {
        $nav = '<nav><a href="/a">The Editorial Desk And Opinion Section</a>'
            . '<a href="/b">Long Form Investigative Reporting Hub</a>'
            . '<a href="/c">Culture Arts And Entertainment Coverage</a>'
            . '<a href="/d">World News And Global Affairs Section</a></nav>';
        $content = '<div id="wrap">' . $nav . '<main><p>' . self::PROSE . '</p></main></div>';

        $result = $this->cleaner->clean($content, BodyCleaningInputs::nothingKnown());

        self::assertStringNotContainsString('<nav', $result);
        self::assertStringContainsString('Fliesstext', $result);
    }
}
