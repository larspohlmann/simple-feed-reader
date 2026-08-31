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
}
