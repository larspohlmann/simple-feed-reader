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

    public function testReportsAnIndexPageWhoseHeadingsAreLinksToOtherArticles(): void
    {
        $html = '<h2><a href="https://example.test/1">Eins</a></h2>'
            . '<h2><a href="https://example.test/2">Zwei</a></h2>'
            . '<h2><a href="https://example.test/3">Drei</a></h2><p>' . str_repeat(self::PROSE, 5) . '</p>';

        self::assertContains('heading_heavy', $this->codesFor($html));
    }

    public function testASectionedEssayIsNotAnIndexPage(): void
    {
        // An Anarchist Library pamphlet: nineteen section titles, all of them
        // plain text, all of them the article's own (#744).
        $html = '<p>' . str_repeat(self::PROSE, 3) . '</p>';
        foreach (range(1, 19) as $section) {
            $html .= '<h2>Abschnitt ' . $section . '</h2><p>Ein kurzer Absatz.</p>';
        }

        self::assertSame([], $this->codesFor($html));
    }

    public function testReportsABodyThatOpensWithTheHeadlineAgain(): void
    {
        $html = '<p>Die Ueberschrift</p><p>' . str_repeat(self::PROSE, 5) . '</p>';

        self::assertContains('duplicate_title', $this->codesFor($html, 'Die Ueberschrift!'));
    }

    public function testAnEmptyBodyIsNotReportedAsHavingNoParagraph(): void
    {
        // A failed extraction has its own marker; reporting it twice would count
        // one broken page as two findings.
        self::assertSame([], $this->codesFor(''));
    }

    public function testThreeHeadingsAreAHubAndTwoAreNot(): void
    {
        $heading = static fn (string $text): string => '<h2><a href="https://example.test/x">' . $text . '</a></h2>';
        $two = $heading('Eins') . $heading('Zwei') . '<p>a</p>';
        $three = $two . $heading('Drei');

        self::assertSame([], $this->codesFor($two));
        self::assertSame(['heading_heavy'], $this->codesFor($three));
    }

    public function testAsManyParagraphsAsLinkedHeadingsIsStillAnArticle(): void
    {
        $heading = static fn (string $text): string => '<h2><a href="https://example.test/x">' . $text . '</a></h2>';
        $balanced = $heading('Eins') . $heading('Zwei') . $heading('Drei') . '<p>a</p><p>b</p><p>c</p>';

        self::assertSame([], $this->codesFor($balanced));
    }

    public function testTheHeadlineIsMatchedPastPunctuationAndCasing(): void
    {
        $html = '<p>die überschrift</p><p>' . str_repeat(self::PROSE, 5) . '</p>';

        self::assertContains('duplicate_title', $this->codesFor($html, 'Die überschrift!'));
    }

    public function testABodyOpeningOnPunctuationAloneIsNotADuplicateTitle(): void
    {
        $html = '<p>—</p><p>' . str_repeat(self::PROSE, 5) . '</p>';

        self::assertSame([], $this->codesFor($html, '!!!'));
    }

    public function testEachMarkerCarriesItsWeightStageAndEvidence(): void
    {
        $heading = static fn (string $text): string => '<h2><a href="https://example.test/x">' . $text . '</a></h2>';
        $markers = $this->markers->detect(
            ExtractedBody::fromHtml($heading('Eins') . $heading('Zwei') . $heading('Drei') . '<p>a</p>'),
            $this->entry(null),
            null,
        );

        self::assertSame('heading_heavy', $markers[0]->code);
        self::assertSame(3, $markers[0]->weight);
        self::assertSame('readability picked an index page', $markers[0]->suspect);
        self::assertSame('3 headings are links to other articles, against 1 paragraphs', $markers[0]->detail);
    }

    public function testTheNoParagraphMarkerNamesItsWeightAndStage(): void
    {
        $markers = $this->markers->detect(
            ExtractedBody::fromHtml('<div>' . str_repeat(self::PROSE, 5) . '</div>'),
            $this->entry(null),
            null,
        );

        self::assertSame(4, $markers[0]->weight);
        self::assertSame('readability picked a non-article region', $markers[0]->suspect);
        self::assertSame('the body holds text but not one <p>', $markers[0]->detail);
    }

    public function testTheDuplicateTitleMarkerNamesItsWeightStageAndTheLine(): void
    {
        $html = '<p>Die Ueberschrift</p><p>' . str_repeat(self::PROSE, 5) . '</p>';
        $markers = $this->markers->detect(
            ExtractedBody::fromHtml($html),
            $this->entry(null, 'Die Ueberschrift'),
            null,
        );

        self::assertSame(2, $markers[0]->weight);
        self::assertSame('LeadingTitleRemover', $markers[0]->suspect);
        self::assertSame('body opens with the headline again: Die Ueberschrift', $markers[0]->detail);
    }

    public function testTheArticleTitleIsCheckedBesideTheFeedTitle(): void
    {
        // Readability's own title and the feed's disagree often enough that a
        // repeated headline hides behind whichever one is not compared.
        $html = '<p>Was Readability las</p><p>' . str_repeat(self::PROSE, 5) . '</p>';

        $markers = $this->markers->detect(
            ExtractedBody::fromHtml($html),
            $this->entry(null, 'Ganz anders im Feed'),
            'Was Readability las',
        );

        self::assertSame('duplicate_title', $markers[0]->code);
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
