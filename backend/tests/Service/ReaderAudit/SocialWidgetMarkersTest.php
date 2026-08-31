<?php

declare(strict_types=1);

namespace App\Tests\Service\ReaderAudit;

use App\Service\ReaderAudit\CleanupMarker;
use App\Service\ReaderAudit\ExtractedBody;
use App\Service\ReaderAudit\SocialWidgetMarkers;
use PHPUnit\Framework\TestCase;

final class SocialWidgetMarkersTest extends TestCase
{
    private SocialWidgetMarkers $markers;

    protected function setUp(): void
    {
        $this->markers = new SocialWidgetMarkers();
    }

    public function testOneShareIntentUrlIsProofOnItsOwn(): void
    {
        // Nothing but a share button ever links to a sharer endpoint, so this
        // needs no second signal and no anchor text — the icon carries none.
        $html = '<p><a href="https://www.facebook.com/sharer/sharer.php?u=https://example.test/a"></a></p>';

        self::assertSame(['share_intent_link'], $this->codesFor($html));
    }

    public function testRecognisesAMailtoShareButtonWhichCarriesNoHostAtAll(): void
    {
        self::assertSame(['share_intent_link'], $this->codesFor('<p><a href="mailto:?subject=Artikel">Mail</a></p>'));
    }

    public function testReportsARowOfIconLinksToDifferentNetworks(): void
    {
        $row = '<p><a href="https://facebook.com/blatt">f</a><a href="https://x.com/blatt">x</a>'
            . '<a href="https://instagram.com/blatt">i</a></p>';

        self::assertSame(['social_row'], $this->codesFor($row));
    }

    public function testAnArticleQuotingAPostIsNotAShareBar(): void
    {
        // One outbound social link with a sentence for its text is a citation;
        // scoring it would flag every article that writes about a post.
        $html = '<p>Dazu schrieb der Minister <a href="https://x.com/minister/status/1">in einem '
            . 'ausfuehrlichen Beitrag auf X am Montagabend</a>.</p>';

        self::assertSame([], $this->codesFor($html));
    }

    public function testTwoNetworksAreNotYetARow(): void
    {
        $html = '<p><a href="https://facebook.com/a">f</a><a href="https://x.com/a">x</a></p>';

        self::assertSame([], $this->codesFor($html));
    }

    /** @return list<string> */
    private function codesFor(string $html): array
    {
        return array_map(
            static fn (CleanupMarker $marker): string => $marker->code,
            $this->markers->detect(ExtractedBody::fromHtml($html)),
        );
    }
}
