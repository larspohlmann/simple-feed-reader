<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\Support;

use App\Enum\TranscriptFormat;
use App\Service\Parser\Support\DeclaredTranscripts;
use App\Tests\Support\FeedItemFixtures;
use PHPUnit\Framework\TestCase;

final class DeclaredTranscriptsTest extends TestCase
{
    use FeedItemFixtures;

    public function testAnItemWithoutTranscriptsDeclaresNone(): void
    {
        self::assertNull(DeclaredTranscripts::of($this->rssItem('<title>x</title>')));
    }

    public function testVttWinsOverEveryOtherTypeWhereverItStands(): void
    {
        $transcript = DeclaredTranscripts::of($this->rssItem(
            '<podcast:transcript url="https://pod.test/ep.html" type="text/html"/>'
            . '<podcast:transcript url="https://pod.test/ep.json" type="application/json"/>'
            . '<podcast:transcript url=" https://pod.test/ep.vtt " type="text/vtt" language="de" rel="captions"/>',
        ));

        self::assertNotNull($transcript);
        self::assertSame('https://pod.test/ep.vtt', $transcript->url);
        self::assertSame(TranscriptFormat::Vtt, $transcript->format);
        self::assertSame('de', $transcript->language);
    }

    public function testATieGoesToTheFirstDeclared(): void
    {
        $transcript = DeclaredTranscripts::of($this->rssItem(
            '<podcast:transcript url="https://pod.test/a.srt" type="application/x-subrip"/>'
            . '<podcast:transcript url="https://pod.test/b.srt" type="application/srt"/>',
        ));

        self::assertSame('https://pod.test/a.srt', $transcript?->url);
    }

    public function testUnknownTypesAndMissingUrlsAreIgnored(): void
    {
        self::assertNull(DeclaredTranscripts::of($this->rssItem(
            '<podcast:transcript url="https://pod.test/ep.txt" type="text/plain"/>'
            . '<podcast:transcript url=" " type="text/vtt"/>',
        )));
    }

    public function testAnEmptyLanguageIsNone(): void
    {
        $transcript = DeclaredTranscripts::of($this->rssItem(
            '<podcast:transcript url="https://pod.test/ep.vtt" type="text/vtt" language=""/>',
        ));

        self::assertNull($transcript?->language);
    }

    public function testALanguageLongerThanTheColumnIsCutToIt(): void
    {
        $transcript = DeclaredTranscripts::of($this->rssItem(
            '<podcast:transcript url="https://pod.test/ep.vtt" type="text/vtt" language="'
            . str_repeat('x', 50) . '"/>',
        ));

        self::assertSame(str_repeat('x', 35), $transcript?->language);
    }
}
