<?php

declare(strict_types=1);

namespace App\Tests\Service\ReaderAudit;

use App\Service\ReaderAudit\BodyShapeMarkers;
use App\Service\ReaderAudit\CleanupMarker;
use App\Service\ReaderAudit\ExtractedBody;
use App\Service\ReaderAudit\SampledEntry;
use PHPUnit\Framework\TestCase;

final class BodyShapeMarkersTest extends TestCase
{
    private const string PROSE =
        'Ein ausreichend langer Absatz mit echtem Fliesstext, der jede Laengenschwelle '
        . 'dieser Regeln sicher ueberschreitet und daher als der Artikel gilt, den der '
        . 'Reader zeigen soll. ';

    private BodyShapeMarkers $markers;

    protected function setUp(): void
    {
        $this->markers = new BodyShapeMarkers();
    }

    public function testACleanArticleEarnsNoMarker(): void
    {
        self::assertSame([], $this->codesFor('<p>' . str_repeat(self::PROSE, 5) . '</p>'));
    }

    public function testAShortArticleIsNotAFinding(): void
    {
        // A radio-script piece is 800 characters and complete; the previous
        // length rule reported every one of them (#744).
        self::assertSame([], $this->codesFor('<p>' . str_repeat(self::PROSE, 3) . '</p>'));
    }

    public function testReportsABodyThatHoldsTextButNotOneParagraph(): void
    {
        self::assertContains('no_paragraphs', $this->codesFor('<div>' . str_repeat(self::PROSE, 5) . '</div>'));
    }

    public function testReportsAnIndexPageThatHasMoreHeadingsThanParagraphs(): void
    {
        $html = '<h2>Eins</h2><h2>Zwei</h2><h2>Drei</h2><p>' . str_repeat(self::PROSE, 5) . '</p>';

        self::assertContains('heading_heavy', $this->codesFor($html));
    }

    public function testReportsAReaderBodyThatShowsLessThanTheFeedAlreadyCarries(): void
    {
        $feedHtml = '<p>' . str_repeat(self::PROSE, 10) . '</p>';
        $body = ExtractedBody::fromHtml('<p>' . str_repeat(self::PROSE, 2) . '</p>');

        $codes = $this->codesOf($this->markers->detect($body, $this->entry($feedHtml), null));

        self::assertContains('body_below_feed', $codes);
    }

    public function testReportsABodyThatOpensWithTheHeadlineAgain(): void
    {
        $html = '<p>Die Ueberschrift</p><p>' . str_repeat(self::PROSE, 5) . '</p>';

        self::assertContains('duplicate_title', $this->codesFor($html, 'Die Ueberschrift!'));
    }

    /** @return list<string> */
    private function codesFor(string $html, ?string $entryTitle = null): array
    {
        return $this->codesOf(
            $this->markers->detect(ExtractedBody::fromHtml($html), $this->entry(null, $entryTitle), null),
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

    private function entry(?string $feedHtml, ?string $title = null): SampledEntry
    {
        return new SampledEntry(
            7,
            3,
            11,
            'Ein Feed',
            $title ?? 'Eine Schlagzeile',
            'https://example.test/a',
            $feedHtml,
            false,
        );
    }
}
