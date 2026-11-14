<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\Support;

use App\Entity\EntryChapter;
use App\Service\Parser\Support\DeclaredChapters;
use App\Tests\Support\FeedItemFixtures;
use PHPUnit\Framework\TestCase;

final class DeclaredChaptersTest extends TestCase
{
    use FeedItemFixtures;

    public function testReadsInlinePodloveChapters(): void
    {
        $chapters = DeclaredChapters::of($this->rssItem(
            '<psc:chapters version="1.2">'
            . '<psc:chapter start="00:00:00.000" title="Intro"/>'
            . '<psc:chapter start="00:04:16.250" title="How did Enigma work?" href="https://x.test"/>'
            . '</psc:chapters>',
        ));

        self::assertEquals(
            [new EntryChapter(0, 'Intro'), new EntryChapter(256, 'How did Enigma work?')],
            $chapters->chapters,
        );
        self::assertNull($chapters->url);
    }

    public function testInlineChaptersWinOverALinkedFile(): void
    {
        $chapters = DeclaredChapters::of($this->rssItem(
            '<podcast:chapters url="https://cdn.test/ch.json" type="application/json+chapters"/>'
            . '<psc:chapters><psc:chapter start="0" title="A"/><psc:chapter start="60" title="B"/></psc:chapters>',
        ));

        self::assertCount(2, $chapters->chapters);
        self::assertNull($chapters->url);
    }

    public function testKeepsTheLinkedFileWhenNothingIsInline(): void
    {
        $chapters = DeclaredChapters::of($this->rssItem(
            '<podcast:chapters url=" https://cdn.test/ch.json " type="application/json+chapters"/>',
        ));

        self::assertSame([], $chapters->chapters);
        self::assertSame('https://cdn.test/ch.json', $chapters->url);
    }

    public function testReadsALinkWrittenAsHref(): void
    {
        $chapters = DeclaredChapters::of($this->rssItem(
            '<podcast:chapters href="https://cdn.test/ch.json" type="application/json+chapters"/>',
        ));

        self::assertSame('https://cdn.test/ch.json', $chapters->url);
    }

    public function testTheSpecUrlWinsOverHref(): void
    {
        $chapters = DeclaredChapters::of($this->rssItem(
            '<podcast:chapters url="https://cdn.test/url.json" href="https://cdn.test/href.json"'
            . ' type="application/json+chapters"/>',
        ));

        self::assertSame('https://cdn.test/url.json', $chapters->url);
    }

    public function testSkipsAChapterWithoutATitleOrAStart(): void
    {
        $chapters = DeclaredChapters::of($this->rssItem(
            '<psc:chapters><psc:chapter start="0" title="A"/><psc:chapter start="x" title="Bad"/>'
            . '<psc:chapter start="30" title=" "/><psc:chapter start="60" title="B"/></psc:chapters>',
        ));

        self::assertEquals([new EntryChapter(0, 'A'), new EntryChapter(60, 'B')], $chapters->chapters);
    }

    public function testAnItemWithoutChaptersDeclaresNone(): void
    {
        $chapters = DeclaredChapters::of($this->rssItem('<title>Plain</title>'));

        self::assertSame([], $chapters->chapters);
        self::assertNull($chapters->url);
    }

    public function testReadsChaptersInAnAtomEntry(): void
    {
        $chapters = DeclaredChapters::of($this->atomEntry(
            '<psc:chapters><psc:chapter start="0" title="A"/><psc:chapter start="60" title="B"/></psc:chapters>',
        ));

        self::assertCount(2, $chapters->chapters);
    }
}
