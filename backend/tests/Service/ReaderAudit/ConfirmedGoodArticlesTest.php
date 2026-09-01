<?php

declare(strict_types=1);

namespace App\Tests\Service\ReaderAudit;

use App\Service\Reader\ExtractionResult;
use App\Service\ReaderAudit\BodyShapeMarkers;
use App\Service\ReaderAudit\CleanupMarkers;
use App\Service\ReaderAudit\ExtractedBody;
use App\Service\ReaderAudit\LeadingChromeMarkers;
use App\Service\ReaderAudit\LeadingEngagementMarkers;
use App\Service\ReaderAudit\PhraseMarkers;
use App\Service\ReaderAudit\SampledEntry;
use App\Service\ReaderAudit\SocialWidgetMarkers;
use PHPUnit\Framework\TestCase;

/**
 * The shapes a human read in the reader and called correct. Each one was a
 * finding once, and each one taught the audit something it was measuring wrong;
 * this holds those verdicts so the same shape cannot come back as a finding.
 *
 * Reduced fixtures rather than the pages themselves: a publisher's markup
 * changes weekly, and what is being pinned is the shape, not the page.
 */
final class ConfirmedGoodArticlesTest extends TestCase
{
    private const string PROSE =
        'Ein ausreichend langer Absatz mit echtem Fliesstext, der die Schwelle fuer einen '
        . 'Prosa-Block sicher ueberschreitet und die Stelle markiert, an der der Artikel '
        . 'beginnt und die Kopfzone endet. ';

    public function testAnArticlesOwnTableOfContentsIsNotAMenu(): void
    {
        // deutschlandfunk.de puts jump marks to its own sections above the
        // article. They point at the page itself, so they lead nowhere a menu
        // would lead.
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
        // A German standfirst runs about 160 characters. While the bar sat at
        // 200 the article never "started", and its own header — headline,
        // standfirst, date, table of contents — was read as the region above it.
        $standfirst = 'Muss man im Pflegeheim künftig ins Doppelzimmer? Angesichts fehlender Plätze und '
            . 'steigender Kosten stellt sich die Frage, wie viel Privatsphäre bleibt.';

        $body = ExtractedBody::fromHtml('<h1>Der Abschied vom Einzelzimmer</h1><p>' . $standfirst . '</p>');

        self::assertCount(1, $body->leadingBlocks());
    }

    public function testAKickerRunIntoTheHeadlineIsNotTheHeadlineRepeated(): void
    {
        // The page writes "Privatsphäre im AltenheimDer Abschied…" as one line
        // where the feed writes the two with a separator. Reduced to letters
        // alone those were identical; reduced to words they are not.
        $body = '<h1>Privatsphäre im AltenheimDer Abschied vom Einzelzimmer</h1><p>' . self::PROSE . '</p>';

        self::assertSame([], $this->markersFor($body, 'Privatsphäre im Altenheim - Der Abschied vom Einzelzimmer'));
    }

    public function testAnInterviewOfShortQuestionsAndAnswersIsProseNotChrome(): void
    {
        // An Attack Magazine Q&A: 111 blocks, none of them long, every one of
        // them the article. The whole body counted as the region above it.
        $interview = '<p>Off the back of the new single, we asked Kashovski our usual questions about '
            . 'fear, regret and the records that made them.</p>';
        foreach (['What is your greatest fear?', 'God\'s judgement.', 'What do you deplore?'] as $line) {
            $interview .= '<p>' . $line . '</p>';
        }

        self::assertSame([], $this->markersFor($interview));
    }

    public function testAnArtistsOwnProfileLinksAreNotAShareBar(): void
    {
        // Spotify, Instagram and TikTok inside the interview's sentences. A
        // share bar stands alone; these are what the article is about.
        $body = '<p>Off the back of <a href="https://open.spotify.com/album/x">the new single</a>, '
            . 'Kashovski — <a href="https://www.instagram.com/kashovski/">Instagram</a>, '
            . '<a href="https://www.tiktok.com/@kashovski">TikTok</a> — answered our questions.</p>'
            . '<p>' . self::PROSE . '</p>';

        self::assertSame([], $this->markersFor($body));
    }

    public function testAPodcastTranscriptIsOneArticleHoweverLongItRuns(): void
    {
        // A Volts transcript is 88,000 characters and entirely the article.
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
        // An Anarchist Library piece: nineteen section titles, all plain text.
        // Counting headings alone called the essay a list of other articles.
        $pamphlet = '<p>' . self::PROSE . '</p>';
        foreach (range(1, 19) as $section) {
            $pamphlet .= '<h2>Abschnitt ' . $section . '</h2><p>Ein kurzer Absatz zum Abschnitt.</p>';
        }

        self::assertSame([], $this->markersFor($pamphlet));
    }

    public function testAFeedBodyLongerThanTheArticleIsNotTheCleanersFault(): void
    {
        // A Beat product piece: 2961 characters in the feed, 1364 on the page.
        // The publisher ships a fuller press release; nothing was trimmed away.
        $article = '<p>' . str_repeat(self::PROSE, 3) . '</p>';
        $fullerFeedBody = '<p>' . str_repeat(self::PROSE, 12) . '</p>';

        self::assertSame([], $this->markersFor($article, feedContentHtml: $fullerFeedBody));
    }

    public function testASkipLinkIsThePagesOwnAffordanceNotAMenu(): void
    {
        // Every Missy Magazine article opens with one. It reads like a menu
        // entry and goes nowhere but further down the same page.
        $skipLink = '<p><a href="#main">Skip to content</a></p>';

        self::assertSame([], $this->markersFor($skipLink . $this->article()));
    }

    public function testAPodcastTranscriptOfHundredsOfShortTurnsIsStillOneArticle(): void
    {
        // A Dwarkesh episode: 557 paragraphs, 123,000 characters, 127 links to
        // the papers being discussed. All of it the article.
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
        $markers = new CleanupMarkers(
            new LeadingChromeMarkers(),
            new LeadingEngagementMarkers(),
            new SocialWidgetMarkers(),
            new BodyShapeMarkers(),
            new PhraseMarkers(),
        );
        $entry = new SampledEntry(
            7,
            42,
            11,
            'Ein Feed',
            $entryTitle,
            'https://example.test/a',
            $feedContentHtml,
            false,
        );
        $result = ExtractionResult::ok('https://example.test/a', $entryTitle, null, null, $html, null);

        return array_map(
            static fn ($marker): string => $marker->code,
            $markers->detect($result, $entry, ExtractedBody::fromHtml($html)),
        );
    }

    private function article(): string
    {
        return '<p>' . str_repeat(self::PROSE, 2) . '</p><p>' . self::PROSE . '</p>';
    }
}
