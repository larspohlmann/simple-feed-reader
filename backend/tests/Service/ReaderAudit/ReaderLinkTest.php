<?php

declare(strict_types=1);

namespace App\Tests\Service\ReaderAudit;

use App\Service\ReaderAudit\ReaderLink;
use App\Service\ReaderAudit\SampledEntry;
use PHPUnit\Framework\TestCase;

final class ReaderLinkTest extends TestCase
{
    public function testOpensTheEntryInsideTheSubscriptionThatHoldsIt(): void
    {
        $entry = $this->entry(514, 42, 'Wadephul: Afrika Chancenkontinent');
        $link = (new ReaderLink('http://localhost:4200'))->to($entry);

        self::assertSame(
            'http://localhost:4200/?subscription=42&entry=514-wadephul-afrika-chancenkontinent',
            $link,
        );
    }

    public function testFoldsAccentsAndUmlautsTheWayTheSpaSlugDoes(): void
    {
        $link = (new ReaderLink('http://localhost:4200'))->to($this->entry(7, 1, 'Grüße aus Zürich'));

        self::assertStringEndsWith('entry=7-grusse-aus-zurich', $link);
    }

    public function testATitleWithoutOneSlugCharacterFallsBackToTheBareId(): void
    {
        // The id is what the SPA parses; the slug is decoration, so a headline
        // that folds to nothing must not produce a trailing hyphen.
        $link = (new ReaderLink('http://localhost:4200/'))->to($this->entry(9, 1, '???'));

        self::assertSame('http://localhost:4200/?subscription=1&entry=9', $link);
    }

    public function testCutsALongHeadlineToEightyCharacters(): void
    {
        // Seven-character words, so the cut at eighty lands mid-word and the
        // length is exactly the limit rather than the limit minus a hyphen.
        $title = str_repeat('abcdef ', 20);

        $link = (new ReaderLink('http://localhost:4200'))->to($this->entry(7, 1, $title));

        $slug = substr($link, (int) strpos($link, 'entry=7-') + 8);
        self::assertSame(80, \strlen($slug));
    }

    public function testACutThatLandsOnAHyphenDoesNotLeaveItHanging(): void
    {
        // Five-character words put a hyphen exactly on the boundary; a slug must
        // never end on one.
        $link = (new ReaderLink('http://localhost:4200'))->to($this->entry(7, 1, str_repeat('wort ', 30)));

        self::assertStringEndsNotWith('-', $link);
        self::assertSame(79, \strlen(substr($link, (int) strpos($link, 'entry=7-') + 8)));
    }

    public function testEscapesAHeadlineThatWouldOtherwiseAddQueryParameters(): void
    {
        // The slug is publisher text; an unescaped "&" would turn the rest of
        // the headline into parameters the SPA then reads as a selection.
        $link = (new ReaderLink('http://localhost:4200'))->to($this->entry(7, 1, 'A & B'));

        self::assertStringNotContainsString('&entry', substr($link, (int) strpos($link, 'entry=')));
        self::assertStringEndsWith('entry=7-a-b', $link);
    }

    public function testARunOfPunctuationBecomesOneHyphenNotSeveral(): void
    {
        $link = (new ReaderLink('http://localhost:4200'))->to($this->entry(7, 1, 'Eins --- Zwei'));

        self::assertStringEndsWith('entry=7-eins-zwei', $link);
    }

    public function testAHeadlineOfNothingButPunctuationLeavesNoHyphenBehind(): void
    {
        // The trim runs on both ends: without it the id would be followed by a
        // bare hyphen the SPA still parses but nobody can read.
        $link = (new ReaderLink('http://localhost:4200'))->to($this->entry(7, 1, '--- Titel ---'));

        self::assertStringEndsWith('entry=7-titel', $link);
    }

    public function testAnUntransliterableHeadlineStillProducesALink(): void
    {
        // Transliteration answers with nothing for a headline it cannot fold;
        // the id must still open the article.
        $link = (new ReaderLink('http://localhost:4200'))->to($this->entry(7, 1, '中文标题'));

        self::assertStringContainsString('entry=7', $link);
    }

    public function testTheBaseUrlIsTakenAsGivenWhicheverWayItEnds(): void
    {
        $entry = $this->entry(7, 1, 'Titel');

        self::assertSame(
            (new ReaderLink('https://reader.example.test'))->to($entry),
            (new ReaderLink('https://reader.example.test/'))->to($entry),
        );
    }

    private function entry(int $entryId, int $subscriptionId, string $title): SampledEntry
    {
        return new SampledEntry(
            $entryId,
            $subscriptionId,
            3,
            'Ein Feed',
            $title,
            'https://example.test/a',
            null,
            false,
        );
    }
}
