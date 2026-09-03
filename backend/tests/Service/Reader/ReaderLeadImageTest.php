<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\LazyImageSources;
use App\Service\Reader\LeadImageCandidate;
use App\Service\Reader\PageImageInventory;
use App\Service\Reader\ReaderLeadImage;
use PHPUnit\Framework\TestCase;

final class ReaderLeadImageTest extends TestCase
{
    private ReaderLeadImage $leadImage;

    protected function setUp(): void
    {
        $this->leadImage = new ReaderLeadImage();
    }

    /** An inventory of a page that draws exactly these plain image URLs. */
    private function pageDrawing(string ...$urls): PageImageInventory
    {
        $images = '';
        foreach ($urls as $url) {
            $images .= '<img src="' . $url . '">';
        }

        return PageImageInventory::fromDocument(HtmlDocumentParser::parseOrNull('<body>' . $images . '</body>'));
    }

    private function pageDrawingNothing(): PageImageInventory
    {
        return PageImageInventory::fromDocument(null);
    }

    /** The inventory of a raw page after LazyImageSources has resolved it. */
    private function inventoryOfResolvedPage(string $pageHtml): PageImageInventory
    {
        $document = HtmlDocumentParser::parseOrNull($pageHtml);
        self::assertNotNull($document);
        (new LazyImageSources())->resolveIn($document);

        return PageImageInventory::fromDocument($document);
    }

    /** Run restore in place and return the serialised body markup. */
    private function restoredBody(
        string $bodyHtml,
        PageImageInventory $pageImages,
        ?string $leadUrl,
        bool $willTopPlace = false,
    ): string {
        $document = HtmlDocumentParser::parseOrNull($bodyHtml);
        self::assertNotNull($document);
        $this->leadImage->restore($document, new LeadImageCandidate($leadUrl, $pageImages), $willTopPlace);

        return (string) $document->body?->innerHTML;
    }

    public function testNeverRestoresAShareRenderIntoAnImagelessBody(): void
    {
        // Substack (#786): a post without pictures reports the subscribe card as
        // its og:image; an imageless body takes any lead, so the card must be
        // refused by what it is, not by where it is drawn.
        $card = 'https://substackcdn.com/image/fetch/$s_!9Uw9!,f_auto/'
            . rawurlencode('https://pub.test/twitter/subscribe-card.jpg?v=1');

        $body = $this->restoredBody('<div><p>Text only.</p></div>', $this->pageDrawingNothing(), $card);

        self::assertStringNotContainsString('<img', $body);
    }

    /** The body markup as the parser round-trips it, with no restore applied. */
    private function unchangedBody(string $bodyHtml): string
    {
        return (string) HtmlDocumentParser::parseOrNull($bodyHtml)?->body?->innerHTML;
    }

    public function testPrependsTheLeadWhenTheBodyBuriesADifferentImage(): void
    {
        // mopo: readability dropped the header photo and kept a different photo
        // deep in the body. The lead belongs back at the top.
        $lead = 'https://cdn.test/hero-photo.jpg';
        $body = '<p>Anzeige</p><p>Story.</p><figure><img src="https://cdn.test/gallery-shot.jpg" alt=""></figure>';

        $result = $this->restoredBody($body, $this->pageDrawing($lead), $lead);

        self::assertStringContainsString('hero-photo.jpg', $result);
        self::assertStringContainsString('gallery-shot.jpg', $result);
        self::assertLessThan(
            strpos($result, 'gallery-shot.jpg'),
            strpos($result, 'hero-photo.jpg'),
            'the lead must lead the body',
        );
    }

    public function testPrependsTheLeadWhenBodyImageSharesOnlyAnArticlePrefix(): void
    {
        $lead = 'https://media.nature.com/d41586-026-02684-1_53170876.jpg';
        $drawnLead = 'https://media.nature.com/d41586-026-02684-1_53170874.jpg';
        $related = 'https://media.nature.com/d41586-026-02684-1_52157812.jpg';
        $body = '<p>Story.</p><figure><img src="' . $related . '" alt=""></figure>';

        $result = $this->restoredBody($body, $this->pageDrawing($drawnLead), $lead);

        self::assertStringContainsString('53170876.jpg', $result);
        self::assertStringContainsString('52157812.jpg', $result);
        self::assertLessThan(
            strpos($result, '52157812.jpg'),
            strpos($result, '53170876.jpg'),
            'the distinct lead asset must lead the body',
        );
    }

    public function testPrependsTheLeadWhenTheBodyHasNoImage(): void
    {
        $lead = 'https://cdn.test/hero-photo.jpg';

        $result = $this->restoredBody('<p>Just words.</p>', $this->pageDrawing($lead), $lead);

        self::assertStringContainsString('hero-photo.jpg', $result);
    }

    public function testRestoresAMetaOnlyLeadIntoAnImagelessBody(): void
    {
        // A text-only article whose lead lives only in the og:meta: the body has
        // no picture to duplicate, so the lead still leads (the old hero behaviour).
        $lead = 'https://cdn.test/hero-photo.jpg';

        $result = $this->restoredBody('<p>Just words.</p>', $this->pageDrawingNothing(), $lead);

        self::assertStringContainsString('hero-photo.jpg', $result);
    }

    public function testLeavesTheBodyWhenItAlreadyShowsTheLead(): void
    {
        // readability kept the lead in the body; re-adding it would stack the photo.
        $lead = 'https://cdn.test/hero-photo.jpg';
        $body = '<p>Intro.</p><figure><img src="https://cdn.test/hero-photo.jpg" alt=""></figure>';

        self::assertSame(
            $this->unchangedBody($body),
            $this->restoredBody($body, $this->pageDrawing($lead), $lead),
        );
    }

    public function testLeavesTheBodyWhenItOpensWithAnImage(): void
    {
        // The body already leads with a picture; the safe choice is to add nothing
        // even when identity cannot confirm it is the same photo.
        $lead = 'https://cdn.test/hero-photo.jpg';
        $body = '<figure><img src="https://cdn.test/some-other.jpg" alt=""></figure><p>Intro.</p>';

        self::assertSame(
            $this->unchangedBody($body),
            $this->restoredBody($body, $this->pageDrawing($lead), $lead),
        );
    }

    public function testLeavesTheBodyWhenTheLeadIsNotDrawnOnThePage(): void
    {
        // beat.de: the og:image is a meta-only share-render, never drawn in the
        // article. It must not be injected — the body already shows the real photo.
        $lead = 'https://cdn.test/share-render.jpg';
        $body = '<p>Intro.</p><figure><img src="https://cdn.test/real-upload.jpg" alt=""></figure>';

        self::assertSame(
            $this->unchangedBody($body),
            $this->restoredBody($body, $this->pageDrawing('https://cdn.test/real-upload.jpg'), $lead),
        );
    }

    public function testIgnoresANonHttpLead(): void
    {
        $body = '<p>Just words.</p>';

        self::assertSame(
            $this->unchangedBody($body),
            $this->restoredBody($body, $this->pageDrawingNothing(), 'javascript:alert(1)'),
        );
        self::assertSame(
            $this->unchangedBody($body),
            $this->restoredBody($body, $this->pageDrawingNothing(), null),
        );
    }

    public function testSkipsRestoringTheHeroWhenAPlayerWillBeTopPlaced(): void
    {
        // heise 487576: an embed poster and the hero are the same picture from
        // different CDNs, so identity cannot match them — but a player is about
        // to be prepended, so the hero must not stack a second copy above it.
        $lead = 'https://cdn.test/hero-photo.jpg';
        $body = '<p>Just words.</p>';

        $result = $this->restoredBody($body, $this->pageDrawing($lead), $lead, true);

        self::assertSame($this->unchangedBody($body), $result);
    }

    public function testStillRestoresTheHeroWhenNothingWillBeTopPlaced(): void
    {
        // willTopPlace=false must behave exactly as before: a legitimately
        // distinct hero on a mid-body-video article is not dropped.
        $lead = 'https://cdn.test/hero-photo.jpg';

        $result = $this->restoredBody('<p>Just words.</p>', $this->pageDrawing($lead), $lead, false);

        self::assertStringContainsString('hero-photo.jpg', $result);
    }

    public function testDrawsALazyLoadedLeadOnceLazyImageSourcesResolvedIt(): void
    {
        // The page ships the real URL on data-src behind a data: placeholder.
        // Digging it out is LazyImageSources' job now; the inventory then reads
        // the resolved src, so the drawn-on-page gate opens for the lead against
        // a body that carries a different picture.
        $lead = 'https://cdn.test/hero-photo.jpg';
        $page = '<html><body><img src="data:image/gif;base64,AAAA" data-src="' . $lead . '">'
            . '<p>Body.</p></body></html>';
        $body = '<p>Text.</p><figure><img src="https://cdn.test/other.jpg" alt=""></figure>';

        $result = $this->restoredBody($body, $this->inventoryOfResolvedPage($page), $lead);

        self::assertStringContainsString('hero-photo.jpg', $result);
    }
}
