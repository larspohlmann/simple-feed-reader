<?php

declare(strict_types=1);

namespace App\Tests\Service\ReaderAudit;

use App\Service\ReaderAudit\CleanupMarker;
use App\Service\ReaderAudit\ExtractedBody;
use App\Service\ReaderAudit\SampledEntry;
use App\Service\ReaderAudit\StructureMarkers;
use PHPUnit\Framework\TestCase;

final class StructureMarkersTest extends TestCase
{
    private const string PROSE =
        'Ein ausreichend langer Absatz mit echtem Fliesstext, der jede Laengenschwelle '
        . 'dieser Regeln sicher ueberschreitet und daher nicht als zu kurzer Koerper '
        . 'gezaehlt wird, sondern als der Artikel, den der Reader zeigen soll. ';

    private StructureMarkers $markers;

    protected function setUp(): void
    {
        $this->markers = new StructureMarkers();
    }

    public function testACleanArticleEarnsNoMarker(): void
    {
        $html = '<p>' . str_repeat(self::PROSE, 5) . 'Ende.</p><img src="https://example.test/lead.jpg">';

        self::assertSame([], $this->codesFor($html));
    }

    public function testReportsABodyShorterThanTheShortestPlausibleArticle(): void
    {
        self::assertContains('body_short', $this->codesFor('<p>Zu kurz.</p>'));
    }

    public function testReportsAReaderBodyThatShowsLessThanTheFeedAlreadyCarries(): void
    {
        // The reader exists to add to the feed body. Falling below it means a
        // cleaner cut article text, not furniture.
        $feedHtml = '<p>' . str_repeat(self::PROSE, 10) . '</p>';
        $body = ExtractedBody::fromHtml('<p>' . str_repeat(self::PROSE, 2) . 'Ende.</p>');

        $codes = $this->codesOf($this->markers->detect($body, $this->entry($feedHtml), null));

        self::assertContains('body_below_feed', $codes);
    }

    public function testReportsABodyThatHoldsTextButNotOneParagraph(): void
    {
        self::assertContains('no_paragraphs', $this->codesFor('<div>' . str_repeat(self::PROSE, 5) . '</div>'));
    }

    public function testReportsALinkDominatedBody(): void
    {
        $links = str_repeat('<a href="/x">Ein Menuepunkt mit Text</a> ', 6);

        self::assertContains('link_dense', $this->codesFor('<p>' . $links . 'Rest.</p>'));
    }

    public function testReportsARunOfListItemsThatAreNothingButALink(): void
    {
        $items = str_repeat('<li><a href="/x">Ressort</a></li>', 4);
        $html = '<p>' . str_repeat(self::PROSE, 5) . '</p><ul>' . $items . '</ul>';

        self::assertContains('link_list', $this->codesFor($html));
    }

    public function testReportsAnIndexPageThatHasMoreHeadingsThanParagraphs(): void
    {
        $html = '<h2>Eins</h2><h2>Zwei</h2><h2>Drei</h2><p>' . str_repeat(self::PROSE, 5) . '</p>';

        self::assertContains('heading_heavy', $this->codesFor($html));
    }

    public function testReportsABodyThatOpensWithTheHeadlineAgain(): void
    {
        $html = '<p>Die Ueberschrift</p><p>' . str_repeat(self::PROSE, 5) . 'Ende.</p>';

        self::assertContains('duplicate_title', $this->codesFor($html, 'Die Ueberschrift!'));
    }

    public function testReportsTheSamePictureRestoredTwice(): void
    {
        $html = '<img src="https://cdn.test/lead-photo.jpg?w=1200"><p>' . str_repeat(self::PROSE, 5)
            . 'Ende.</p><img src="https://other.test/x/lead-photo.webp">';

        self::assertContains('repeated_image', $this->codesFor($html));
    }

    public function testReportsAMissingLeadImageOnlyWhenTheFeedCarriesOne(): void
    {
        $html = '<p>' . str_repeat(self::PROSE, 5) . 'Ende.</p>';

        self::assertContains('image_missing', $this->codesOf(
            $this->markers->detect(ExtractedBody::fromHtml($html), $this->entry(null, hasImage: true), null),
        ));
        self::assertNotContains('image_missing', $this->codesFor($html));
    }

    public function testReportsABlockThatAppearsTwice(): void
    {
        $duplicated = '<p>' . self::PROSE . '</p>';

        self::assertContains('repeated_block', $this->codesFor($duplicated . $duplicated . '<p>Ende.</p>'));
    }

    public function testReportsAShoutOfShortAllCapsLines(): void
    {
        $html = '<p>' . str_repeat(self::PROSE, 5) . 'Ende.</p><p>MEHR</p><p>THEMEN</p><p>SERVICE</p>';

        self::assertContains('shouting_lines', $this->codesFor($html));
    }

    public function testReportsABodyThatStopsMidSentence(): void
    {
        self::assertContains('truncated_tail', $this->codesFor('<p>' . str_repeat(self::PROSE, 5) . 'und dann'));
    }

    public function testABodyClosingOnABracketCountsAsEndedBecauseCaptionsDo(): void
    {
        $html = '<p>' . str_repeat(self::PROSE, 5) . '</p><figcaption>Der Sieger [Getty Images]</figcaption>';

        self::assertNotContains('truncated_tail', $this->codesFor($html));
    }

    /** @return list<string> */
    private function codesFor(string $html, ?string $entryTitle = null): array
    {
        return $this->codesOf(
            $this->markers->detect(ExtractedBody::fromHtml($html), $this->entry(null, title: $entryTitle), null),
        );
    }

    /**
     * @param list<CleanupMarker> $markers
     *
     * @return list<string>
     */
    private function codesOf(array $markers): array
    {
        return array_map(static fn (CleanupMarker $marker): string => $marker->code, $markers);
    }

    private function entry(?string $feedHtml, bool $hasImage = false, ?string $title = null): SampledEntry
    {
        return new SampledEntry(
            7,
            3,
            11,
            'Ein Feed',
            $title ?? 'Eine Schlagzeile',
            'https://example.test/a',
            $feedHtml,
            $hasImage,
        );
    }
}
