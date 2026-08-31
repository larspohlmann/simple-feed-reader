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

    public function testAnEmptyBodyIsNotReportedAsHavingNoParagraph(): void
    {
        // A failed extraction has its own marker; reporting it twice would count
        // one broken page as two findings.
        self::assertSame([], $this->codesFor(''));
    }

    public function testThreeHeadingsAreAHubAndTwoAreNot(): void
    {
        $two = '<h2>Eins</h2><h2>Zwei</h2><p>a</p>';
        $three = '<h2>Eins</h2><h2>Zwei</h2><h2>Drei</h2><p>a</p>';

        self::assertSame([], $this->codesFor($two));
        self::assertSame(['heading_heavy'], $this->codesFor($three));
    }

    public function testAsManyParagraphsAsHeadingsIsStillAnArticle(): void
    {
        $balanced = '<h2>Eins</h2><h2>Zwei</h2><h2>Drei</h2><p>a</p><p>b</p><p>c</p>';

        self::assertSame([], $this->codesFor($balanced));
    }

    public function testABodyOfExactlyTheHugeLimitIsStillOneArticle(): void
    {
        $atLimit = '<p>' . str_repeat('a', 40_000) . '</p>';
        $overLimit = '<p>' . str_repeat('a', 40_001) . '</p>';

        self::assertSame([], $this->codesFor($atLimit));
        self::assertSame(['body_huge'], $this->codesFor($overLimit));
    }

    public function testAFeedBodyUnderTheSubstantialLengthIsATeaserAndProvesNothing(): void
    {
        // Below this the feed carries a summary, which the reader is supposed to
        // beat; comparing against it would report every well-extracted article.
        $teaser = '<p>' . str_repeat('a', 799) . '</p>';
        $full = '<p>' . str_repeat('a', 800) . '</p>';
        $short = ExtractedBody::fromHtml('<p>' . str_repeat('b', 100) . '</p>');

        self::assertSame([], $this->codesOf($this->markers->detect($short, $this->entry($teaser), null)));
        self::assertSame(
            ['body_below_feed'],
            $this->codesOf($this->markers->detect($short, $this->entry($full), null)),
        );
    }

    public function testAReaderBodyAtSixtyPercentOfTheFeedIsNotAShortfall(): void
    {
        $feedHtml = '<p>' . str_repeat('a', 1000) . '</p>';
        $atShare = ExtractedBody::fromHtml('<p>' . str_repeat('b', 600) . '</p>');
        $under = ExtractedBody::fromHtml('<p>' . str_repeat('b', 599) . '</p>');

        self::assertSame([], $this->codesOf($this->markers->detect($atShare, $this->entry($feedHtml), null)));
        self::assertSame(
            ['body_below_feed'],
            $this->codesOf($this->markers->detect($under, $this->entry($feedHtml), null)),
        );
    }

    public function testTheFeedBodyIsMeasuredAsTextNotAsMarkup(): void
    {
        // A teaser wrapped in a kilobyte of tracking markup would otherwise read
        // as a full article and make every extraction of it look short.
        $markupHeavyTeaser = '<div class="' . str_repeat('x', 900) . '"><p>Kurz.</p></div>';
        $short = ExtractedBody::fromHtml('<p>Auch kurz.</p>');

        self::assertSame([], $this->codesOf($this->markers->detect($short, $this->entry($markupHeavyTeaser), null)));
    }

    public function testTheFeedBodyIsMeasuredInCharactersNotBytes(): void
    {
        $umlautTeaser = '<p>' . str_repeat('ä', 700) . '</p>';
        $short = ExtractedBody::fromHtml('<p>Kurz.</p>');

        self::assertSame([], $this->codesOf($this->markers->detect($short, $this->entry($umlautTeaser), null)));
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
        $markers = $this->markers->detect(
            ExtractedBody::fromHtml('<h2>Eins</h2><h2>Zwei</h2><h2>Drei</h2><p>a</p>'),
            $this->entry(null),
            null,
        );

        self::assertSame('heading_heavy', $markers[0]->code);
        self::assertSame(3, $markers[0]->weight);
        self::assertSame('readability picked an index page', $markers[0]->suspect);
        self::assertSame('3 headings against 1 paragraphs', $markers[0]->detail);
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

    public function testTheHugeBodyMarkerNamesItsWeightAndLength(): void
    {
        $markers = $this->markers->detect(
            ExtractedBody::fromHtml('<p>' . str_repeat('a', 40_001) . '</p>'),
            $this->entry(null),
            null,
        );

        self::assertSame(2, $markers[0]->weight);
        self::assertSame('readability picked a section or index page', $markers[0]->suspect);
        self::assertSame('40001 characters — more than one article', $markers[0]->detail);
    }

    public function testTheShortfallMarkerNamesBothLengths(): void
    {
        $markers = $this->markers->detect(
            ExtractedBody::fromHtml('<p>' . str_repeat('b', 100) . '</p>'),
            $this->entry('<p>' . str_repeat('a', 1000) . '</p>'),
            null,
        );

        self::assertSame(3, $markers[0]->weight);
        self::assertSame('the cleaners over-trimmed', $markers[0]->suspect);
        self::assertSame('reader shows 100 chars, the feed body already has 1000', $markers[0]->detail);
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
