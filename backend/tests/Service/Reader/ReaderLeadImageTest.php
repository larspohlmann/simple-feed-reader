<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\ReaderLeadImage;
use PHPUnit\Framework\TestCase;

final class ReaderLeadImageTest extends TestCase
{
    private ReaderLeadImage $leadImage;

    protected function setUp(): void
    {
        $this->leadImage = new ReaderLeadImage();
    }

    /** A page that renders the lead photo, so the "drawn on the page" gate opens. */
    private function pageShowing(string $leadUrl): string
    {
        return '<html><body><figure class="headerImage"><img src="' . $leadUrl . '"></figure>'
            . '<p>Body copy.</p></body></html>';
    }

    public function testPrependsTheLeadWhenTheBodyBuriesADifferentImage(): void
    {
        // mopo: readability dropped the header photo and kept a different photo
        // deep in the body. The lead belongs back at the top.
        $lead = 'https://cdn.test/hero-photo.jpg';
        $body = '<p>Anzeige</p><p>Story.</p><figure><img src="https://cdn.test/gallery-shot.jpg" alt=""></figure>';

        $result = $this->leadImage->restore($body, $this->pageShowing($lead), $lead);

        self::assertStringContainsString('hero-photo.jpg', $result);
        self::assertStringContainsString('gallery-shot.jpg', $result);
        self::assertLessThan(
            strpos($result, 'gallery-shot.jpg'),
            strpos($result, 'hero-photo.jpg'),
            'the lead must lead the body',
        );
    }

    public function testPrependsTheLeadWhenTheBodyHasNoImage(): void
    {
        $lead = 'https://cdn.test/hero-photo.jpg';

        $result = $this->leadImage->restore('<p>Just words.</p>', $this->pageShowing($lead), $lead);

        self::assertStringContainsString('hero-photo.jpg', $result);
    }

    public function testRestoresAMetaOnlyLeadIntoAnImagelessBody(): void
    {
        // A text-only article whose lead lives only in the og:meta: the body has
        // no picture to duplicate, so the lead still leads (the old hero behaviour).
        $lead = 'https://cdn.test/hero-photo.jpg';
        $page = '<html><body><p>All text; the picture is rendered by script only.</p></body></html>';

        $result = $this->leadImage->restore('<p>Just words.</p>', $page, $lead);

        self::assertStringContainsString('hero-photo.jpg', $result);
    }

    public function testLeavesTheBodyWhenItAlreadyShowsTheLead(): void
    {
        // readability kept the lead in the body; re-adding it would stack the photo.
        $lead = 'https://cdn.test/hero-photo.jpg';
        $body = '<p>Intro.</p><figure><img src="https://cdn.test/hero-photo.jpg" alt=""></figure>';

        self::assertSame($body, $this->leadImage->restore($body, $this->pageShowing($lead), $lead));
    }

    public function testLeavesTheBodyWhenItOpensWithAnImage(): void
    {
        // The body already leads with a picture; the safe choice is to add nothing
        // even when identity cannot confirm it is the same photo.
        $lead = 'https://cdn.test/hero-photo.jpg';
        $body = '<figure><img src="https://cdn.test/some-other.jpg" alt=""></figure><p>Intro.</p>';

        self::assertSame($body, $this->leadImage->restore($body, $this->pageShowing($lead), $lead));
    }

    public function testLeavesTheBodyWhenTheLeadIsNotDrawnOnThePage(): void
    {
        // beat.de: the og:image is a meta-only share-render, never drawn in the
        // article. It must not be injected — the body already shows the real photo.
        $lead = 'https://cdn.test/share-render.jpg';
        $page = '<html><body><p>Intro.</p><figure><img src="https://cdn.test/real-upload.jpg"></figure></body></html>';
        $body = '<p>Intro.</p><figure><img src="https://cdn.test/real-upload.jpg" alt=""></figure>';

        self::assertSame($body, $this->leadImage->restore($body, $page, $lead));
    }

    public function testIgnoresANonHttpLead(): void
    {
        $body = '<p>Just words.</p>';

        self::assertSame($body, $this->leadImage->restore($body, '<html><body></body></html>', 'javascript:alert(1)'));
        self::assertSame($body, $this->leadImage->restore($body, '<html><body></body></html>', null));
    }

    public function testFindsALazyLoadedLeadByItsDataSource(): void
    {
        // The page ships the real URL on data-src with a blank placeholder in src.
        $lead = 'https://cdn.test/hero-photo.jpg';
        $page = '<html><body><picture><img src="/blank.gif" data-src="' . $lead . '"></picture>'
            . '<p>Body.</p></body></html>';

        $result = $this->leadImage->restore('<p>Just words.</p>', $page, $lead);

        self::assertStringContainsString('hero-photo.jpg', $result);
    }
}
