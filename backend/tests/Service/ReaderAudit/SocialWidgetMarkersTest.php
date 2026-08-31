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

    public function testMatchesAShareUrlWhateverItsCasing(): void
    {
        // Publishers spell the host and the path both ways; a case-sensitive
        // match would report the same site as clean on half its articles.
        $html = '<p><a href="https://WWW.Facebook.com/Sharer/sharer.php?u=x">f</a></p>';

        self::assertSame(['share_intent_link'], $this->codesFor($html));
    }

    public function testThreeNetworksAreARowAndTwoAreNot(): void
    {
        $two = '<p><a href="https://facebook.com/a">f</a><a href="https://x.com/a">x</a></p>';
        $three = $two . '<p><a href="https://instagram.com/a">i</a></p>';

        self::assertSame([], $this->codesFor($two));
        self::assertSame(['social_row'], $this->codesFor($three));
    }

    public function testTheSameNetworkLinkedThreeTimesIsOneNetwork(): void
    {
        $repeated = str_repeat('<p><a href="https://facebook.com/a">f</a></p>', 3);

        self::assertSame([], $this->codesFor($repeated));
    }

    public function testASubdomainOfANetworkCountsAsThatNetwork(): void
    {
        $html = '<p><a href="https://de-de.facebook.com/a">f</a><a href="https://mobile.x.com/a">x</a>'
            . '<a href="https://www.instagram.com/a">i</a></p>';

        self::assertSame(['social_row'], $this->codesFor($html));
    }

    public function testAHostThatMerelyEndsInANetworksNameIsNotThatNetwork(): void
    {
        $html = '<p><a href="https://notfacebook.com/a">a</a><a href="https://myx.com/a">b</a>'
            . '<a href="https://instagram.com/a">c</a></p>';

        self::assertSame([], $this->codesFor($html));
    }

    public function testAnAnchorOfExactlyTwentyFiveCharactersIsStillAnIconLabel(): void
    {
        $exactly = str_repeat('a', 25);
        $oneMore = str_repeat('a', 26);
        $row = static fn (string $text): string => '<p>'
            . '<a href="https://facebook.com/a">' . $text . '</a>'
            . '<a href="https://x.com/a">' . $text . '</a>'
            . '<a href="https://instagram.com/a">' . $text . '</a></p>';

        self::assertSame(['social_row'], $this->codesFor($row($exactly)));
        self::assertSame([], $this->codesFor($row($oneMore)));
    }

    public function testTheAnchorLimitCountsCharactersNotBytes(): void
    {
        // Twenty-five umlauts are fifty bytes; counting bytes would call this
        // row of icons a row of sentences and report nothing.
        $umlauts = str_repeat('ä', 25);
        $html = '<p><a href="https://facebook.com/a">' . $umlauts . '</a>'
            . '<a href="https://x.com/a">' . $umlauts . '</a>'
            . '<a href="https://instagram.com/a">' . $umlauts . '</a></p>';

        self::assertSame(['social_row'], $this->codesFor($html));
    }

    public function testEachMarkerCarriesItsWeightStageAndTheEvidence(): void
    {
        $intent = $this->markers->detect(ExtractedBody::fromHtml(
            '<p><a href="https://x.com/intent/tweet?url=a">t</a></p>',
        ));
        $row = $this->markers->detect(ExtractedBody::fromHtml(
            '<p><a href="https://facebook.com/a">f</a><a href="https://x.com/a">x</a>'
            . '<a href="https://reddit.com/a">r</a></p>',
        ));

        self::assertSame(4, $intent[0]->weight);
        self::assertSame('ShareWidgetRemover', $intent[0]->suspect);
        self::assertSame('share button: https://x.com/intent/tweet?url=a', $intent[0]->detail);
        self::assertSame(3, $row[0]->weight);
        self::assertSame('icon links to facebook.com, x.com, reddit.com', $row[0]->detail);
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
