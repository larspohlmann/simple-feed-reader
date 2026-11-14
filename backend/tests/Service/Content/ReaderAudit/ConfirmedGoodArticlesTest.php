<?php

declare(strict_types=1);

namespace App\Tests\Service\ReaderAudit;

use App\Service\Reader\Model\ExtractionResultModel;
use App\Service\ReaderAudit\Model\ExtractedBodyModel;
use App\Service\ReaderAudit\Model\SampledEntryModel;
use App\Tests\Support\AuditMarkers;
use PHPUnit\Framework\TestCase;

/**
 * Shapes a human read in the reader and called correct, each a finding once (#746). Reduced fixtures, because a
 * publisher's markup changes weekly and the shape is what is pinned.
 */
final class ConfirmedGoodArticlesTest extends TestCase
{
    private const string PROSE =
        'Ein ausreichend langer Absatz mit echtem Fliesstext, der die Schwelle fuer einen '
        . 'Prosa-Block sicher ueberschreitet und die Stelle markiert, an der der Artikel '
        . 'beginnt und die Kopfzone endet. ';

    public function testAnArticlesOwnTableOfContentsIsNotAMenu(): void
    {
        $toc = '<p>Inhalt</p><ul>'
            . '<li><a href="#eins">Regelfall Einzelzimmer</a></li>'
            . '<li><a href="#zwei">Fehlende Plätze, steigende Kosten</a></li>'
            . '<li><a href="#drei">Was gegen die Abschaffung spricht</a></li>'
            . '<li><a href="#vier">Vorteile beim Eingewöhnen</a></li>'
            . '</ul>';

        self::assertSame([], $this->markersFor($toc . $this->article()));
    }

    public function testAStandfirstShorterThanAParagraphStillStartsTheArticle(): void
    {
        $standfirst = 'Muss man im Pflegeheim künftig ins Doppelzimmer? Angesichts fehlender Plätze und '
            . 'steigender Kosten stellt sich die Frage, wie viel Privatsphäre bleibt.';

        $body = ExtractedBodyModel::fromHtml('<h1>Der Abschied vom Einzelzimmer</h1><p>' . $standfirst . '</p>');

        self::assertCount(1, AuditMarkers::leadingRegion()->blocksOf($body));
    }

    public function testAKickerRunIntoTheHeadlineIsNotTheHeadlineRepeated(): void
    {
        $body = '<h1>Privatsphäre im AltenheimDer Abschied vom Einzelzimmer</h1><p>' . self::PROSE . '</p>';

        self::assertSame([], $this->markersFor($body, 'Privatsphäre im Altenheim - Der Abschied vom Einzelzimmer'));
    }

    public function testAnInterviewOfShortQuestionsAndAnswersIsProseNotChrome(): void
    {
        $interview = '<p>Off the back of the new single, we asked Kashovski our usual questions about '
            . 'fear, regret and the records that made them.</p>';
        foreach (['What is your greatest fear?', 'God\'s judgement.', 'What do you deplore?'] as $line) {
            $interview .= '<p>' . $line . '</p>';
        }

        self::assertSame([], $this->markersFor($interview));
    }

    public function testAnArtistsOwnProfileLinksAreNotAShareBar(): void
    {
        $body = '<p>Off the back of <a href="https://open.spotify.com/album/x">the new single</a>, '
            . 'Kashovski — <a href="https://www.instagram.com/kashovski/">Instagram</a>, '
            . '<a href="https://www.tiktok.com/@kashovski">TikTok</a> — answered our questions.</p>'
            . '<p>' . self::PROSE . '</p>';

        self::assertSame([], $this->markersFor($body));
    }

    public function testAPodcastTranscriptIsOneArticleHoweverLongItRuns(): void
    {
        $transcript = '<p>' . str_repeat(self::PROSE, 400) . '</p>';

        self::assertSame([], $this->markersFor($transcript));
    }

    public function testANewsletterConsentLineInsideAnArticleIsNotAConsentWall(): void
    {
        $body = '<p>' . str_repeat(self::PROSE, 8) . '</p>'
            . '<p>Mit der Anmeldung willigen Sie der Verarbeitung Ihrer Daten gemäß unserer '
            . 'Datenschutzerklärung ein.</p>';

        self::assertSame([], $this->markersFor($body));
    }

    public function testASectionedPamphletIsNotAnIndexPage(): void
    {
        $pamphlet = '<p>' . self::PROSE . '</p>';
        foreach (range(1, 19) as $section) {
            $pamphlet .= '<h2>Abschnitt ' . $section . '</h2><p>Ein kurzer Absatz zum Abschnitt.</p>';
        }

        self::assertSame([], $this->markersFor($pamphlet));
    }

    public function testAFeedBodyLongerThanTheArticleIsNotTheCleanersFault(): void
    {
        $article = '<p>' . str_repeat(self::PROSE, 3) . '</p>';
        $fullerFeedBody = '<p>' . str_repeat(self::PROSE, 12) . '</p>';

        self::assertSame([], $this->markersFor($article, feedContentHtml: $fullerFeedBody));
    }

    public function testASkipLinkIsThePagesOwnAffordanceNotAMenu(): void
    {
        $skipLink = '<p><a href="#main">Skip to content</a></p>';

        self::assertSame([], $this->markersFor($skipLink . $this->article()));
    }

    public function testAPodcastTranscriptOfHundredsOfShortTurnsIsStillOneArticle(): void
    {
        $transcript = '';
        foreach (range(1, 60) as $turn) {
            $transcript .= '<p>Ein Redebeitrag mit <a href="https://arxiv.org/abs/' . $turn . '">einer Quelle</a> '
                . 'und genug Text, dass er als Absatz zaehlt und nicht als Beschriftung. ' . self::PROSE . '</p>';
        }

        self::assertSame([], $this->markersFor($transcript));
    }

    /** @return list<string> */
    private function markersFor(
        string $html,
        string $entryTitle = 'Eine Schlagzeile',
        ?string $feedContentHtml = null,
    ): array {
        $markers = AuditMarkers::cleanupMarkers();
        $entry = new SampledEntryModel(
            7,
            42,
            11,
            'Ein Feed',
            $entryTitle,
            'https://example.test/a',
            $feedContentHtml,
            false,
        );
        $result = ExtractionResultModel::ok('https://example.test/a', $entryTitle, null, null, $html, null);

        return array_map(
            static fn ($marker): string => $marker->code,
            $markers->detect($result, $entry, ExtractedBodyModel::fromHtml($html)),
        );
    }

    private function article(): string
    {
        return '<p>' . str_repeat(self::PROSE, 2) . '</p><p>' . self::PROSE . '</p>';
    }
}
