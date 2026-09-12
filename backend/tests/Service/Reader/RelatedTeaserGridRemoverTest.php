<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\RelatedTeaserGridRemover;
use App\Service\Reader\Slideshow\ContainerSignature;
use PHPUnit\Framework\TestCase;

final class RelatedTeaserGridRemoverTest extends TestCase
{
    private const string TEASER_TEXT =
        'Ein kurzer Anrisstext, der die verlinkte Schlagzeile begleitet und '
        . 'zusammen mit ihr die Teaser-Karte bildet.';

    private RelatedTeaserGridRemover $remover;

    protected function setUp(): void
    {
        $this->remover = new RelatedTeaserGridRemover();
    }

    public function testRemovesAGridOfThreeHeadlineLinkedThumbnailCards(): void
    {
        $html = '<div><p>Article prose that the reader keeps.</p>'
            . $this->grid([
                ['t1.webp', '/nachrichten/a,prozess-1.html', 'Prozess: Zwoelf Jahre Haft fuer Drogenschmuggel'],
                ['t2.webp', '/nachrichten/b,prozess-2.html', 'Lange Haftstrafen nach Kokainfund im Hafen'],
                ['t3.webp', '/nachrichten/c,prozess-3.html', 'Ermittler stellen Rekordmenge Rauschgift sicher'],
            ])
            . '</div>';

        $result = $this->removed($html);

        self::assertStringNotContainsString('t1.webp', $result);
        self::assertStringNotContainsString('t2.webp', $result);
        self::assertStringNotContainsString('t3.webp', $result);
        self::assertStringContainsString('Article prose that the reader keeps.', $result);
    }

    public function testKeepsCardsThatAllLinkToTheSameArticle(): void
    {
        // Three thumbnails around one repeated headline link is a single story's
        // gallery, not a grid of separate destinations.
        $html = '<div><p>Article prose that the reader keeps.</p>'
            . $this->grid([
                ['t1.webp', '/nachrichten/same,prozess-9.html', 'Der grosse Drogenprozess in Hamburg'],
                ['t2.webp', '/nachrichten/same,prozess-9.html', 'Der grosse Drogenprozess in Hamburg'],
                ['t3.webp', '/nachrichten/same,prozess-9.html', 'Der grosse Drogenprozess in Hamburg'],
            ])
            . '</div>';

        $result = $this->removed($html);

        self::assertStringContainsString('t1.webp', $result);
    }

    public function testRemovesAGridWhoseCardsLinkToOtherSites(): void
    {
        // A related grid can point off-site (syndicated recommendations); the
        // headline-linked-thumbnail shape, not the link host, marks it as chrome.
        $html = '<div><p>Article prose that the reader keeps.</p>'
            . $this->grid([
                ['t1.webp', 'https://elsewhere.example/a', 'Ein Bericht auf einer anderen Seite'],
                ['t2.webp', 'https://elsewhere.example/b', 'Noch ein Beitrag von anderswo im Netz'],
                ['t3.webp', 'https://elsewhere.example/c', 'Ein dritter externer Artikel dazu'],
            ])
            . '</div>';

        $result = $this->removed($html);

        self::assertStringNotContainsString('t1.webp', $result);
        self::assertStringContainsString('Article prose that the reader keeps.', $result);
    }

    public function testKeepsAContainerAlreadyClaimedByASlideshowRecognizer(): void
    {
        // The slideshow subsystem owns real carousels-with-linked-captions; a
        // container it recognised is never a related grid, whatever its shape.
        $html = '<div><p>Article prose that the reader keeps.</p>'
            . $this->grid([
                ['t1.webp', '/nachrichten/a,prozess-1.html', 'Prozess: Zwoelf Jahre Haft fuer Drogenschmuggel'],
                ['t2.webp', '/nachrichten/b,prozess-2.html', 'Lange Haftstrafen nach Kokainfund im Hafen'],
                ['t3.webp', '/nachrichten/c,prozess-3.html', 'Ermittler stellen Rekordmenge Rauschgift sicher'],
            ])
            . '</div>';

        $signature = ContainerSignature::fromClassAttribute('contentbox list');
        self::assertNotNull($signature);

        $result = $this->removed($html, [$signature]);

        self::assertStringContainsString('t1.webp', $result);
    }

    public function testKeepsASlideshowWhoseCaptionsCarryLinks(): void
    {
        // The links live in the captions (a credit, a source), not in per-slide
        // headlines pointing to other articles, so this is the article's own
        // gallery and stays.
        $html = '<div><p>Article prose that the reader keeps.</p>'
            . '<div class="slideshow">'
            . $this->captionSlide('s1.webp', '/foto/quelle-1', 'Foto: Erste Quelle')
            . $this->captionSlide('s2.webp', '/foto/quelle-2', 'Foto: Zweite Quelle')
            . $this->captionSlide('s3.webp', '/foto/quelle-3', 'Foto: Dritte Quelle')
            . '</div></div>';

        $result = $this->removed($html);

        self::assertStringContainsString('s1.webp', $result);
    }

    public function testKeepsABlockWithOnlyTwoCards(): void
    {
        // Two teasers are a pair, not a grid; the floor keeps a lone "read next"
        // link with a thumbnail from being mistaken for chrome.
        $html = '<div><p>Article prose that the reader keeps.</p>'
            . $this->grid([
                ['t1.webp', '/nachrichten/a,prozess-1.html', 'Prozess: Zwoelf Jahre Haft fuer Drogenschmuggel'],
                ['t2.webp', '/nachrichten/b,prozess-2.html', 'Lange Haftstrafen nach Kokainfund im Hafen'],
            ])
            . '</div>';

        $result = $this->removed($html);

        self::assertStringContainsString('t1.webp', $result);
    }

    public function testNeverRemovesTheArticleRootItself(): void
    {
        // When the cards' shared container is the article root, removing it would
        // take the whole story; the root guard must leave it in place.
        $html = $this->grid([
            ['t1.webp', '/nachrichten/a,prozess-1.html', 'Prozess: Zwoelf Jahre Haft fuer Drogenschmuggel'],
            ['t2.webp', '/nachrichten/b,prozess-2.html', 'Lange Haftstrafen nach Kokainfund im Hafen'],
            ['t3.webp', '/nachrichten/c,prozess-3.html', 'Ermittler stellen Rekordmenge Rauschgift sicher'],
        ], '<article><p>Article prose that the reader keeps.</p>', '</article>');

        $result = $this->removed($html);

        self::assertStringContainsString('Article prose that the reader keeps.', $result);
        self::assertStringContainsString('t1.webp', $result);
    }

    public function testRemovesAGridWrappedInASection(): void
    {
        // A related grid whose immediate wrapper is a <section> is still chrome;
        // a sectioning element is a removable container, not an article root.
        $html = '<div><p>Article prose that the reader keeps.</p>'
            . $this->grid([
                ['t1.webp', '/nachrichten/a,prozess-1.html', 'Prozess: Zwoelf Jahre Haft fuer Drogenschmuggel'],
                ['t2.webp', '/nachrichten/b,prozess-2.html', 'Lange Haftstrafen nach Kokainfund im Hafen'],
                ['t3.webp', '/nachrichten/c,prozess-3.html', 'Ermittler stellen Rekordmenge Rauschgift sicher'],
            ], '<section class="related">', '</section>')
            . '</div>';

        $result = $this->removed($html);

        self::assertStringNotContainsString('t1.webp', $result);
        self::assertStringContainsString('Article prose that the reader keeps.', $result);
    }

    public function testKeepsAProseSectionWhoseSubheadingsLinkOut(): void
    {
        // A real article section: several subheadings that link to sources, real
        // paragraphs, and one illustration. The shared block carries substantial
        // prose, so it is not a card and must survive.
        $prose = str_repeat('Ein langer Absatz mit echtem Fliesstext ueber das Thema. ', 6);
        $html = '<div><p>Article prose that the reader keeps.</p>'
            . '<div class="content"><img src="https://images.ndr.de/figure.webp" alt="" />'
            . '<h2><a href="/quelle/a">Erste Quelle zum Nachlesen im Archiv</a></h2><p>' . $prose . '</p>'
            . '<h2><a href="/quelle/b">Zweite Quelle mit weiteren Belegen dazu</a></h2><p>' . $prose . '</p>'
            . '<h2><a href="/quelle/c">Dritte Quelle rundet die Recherche ab</a></h2><p>' . $prose . '</p>'
            . '</div></div>';

        $result = $this->removed($html);

        self::assertStringContainsString('figure.webp', $result);
    }

    public function testKeepsHeadlineLinksThatHaveNoThumbnail(): void
    {
        // A list of headline links without images is a table of contents, not a
        // teaser grid; a card needs a thumbnail.
        $html = '<div><p>Article prose that the reader keeps.</p>'
            . '<div class="toc">'
            . '<h2><a href="https://site.test/a">Prozess: Zwoelf Jahre Haft fuer Drogenschmuggel</a></h2>'
            . '<h2><a href="https://site.test/b">Lange Haftstrafen nach Kokainfund im Hafen</a></h2>'
            . '<h2><a href="https://site.test/c">Ermittler stellen Rekordmenge Rauschgift sicher</a></h2>'
            . '</div></div>';

        $result = $this->removed($html);

        self::assertStringContainsString('class="toc"', $result);
    }

    private function captionSlide(string $image, string $href, string $caption): string
    {
        return '<figure><img src="https://images.ndr.de/' . $image . '" alt="" />'
            . '<figcaption>' . $caption . ' <a href="' . $href . '">mehr</a></figcaption></figure>';
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}> $cards
     */
    private function grid(
        array $cards,
        string $wrapOpen = '<div class="contentbox list">',
        string $wrapClose = '</div>',
    ): string {
        $html = $wrapOpen;
        foreach ($cards as [$image, $href, $headline]) {
            $html .= '<div class="teaser">'
                . '<div class="teaserimage"><img src="https://images.ndr.de/' . $image . '" alt="" /></div>'
                . '<div class="teaserpadding"><div class="headlinewrapper"><h2>'
                . '<a href="' . $href . '">' . $headline . '</a></h2></div>'
                . '<div class="teasertext"><p>' . self::TEASER_TEXT . '</p></div></div>'
                . '</div>';
        }

        return $html . $wrapClose;
    }

    /**
     * @param list<ContainerSignature> $slideshowContainers
     */
    private function removed(string $html, array $slideshowContainers = []): string
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        self::assertNotNull($document);

        $this->remover->removeFrom($document, $slideshowContainers);

        return $document->saveHtml();
    }
}
