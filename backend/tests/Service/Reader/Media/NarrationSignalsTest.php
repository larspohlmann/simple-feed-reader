<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\NarrationSignals;
use Dom\Element;
use PHPUnit\Framework\TestCase;

final class NarrationSignalsTest extends TestCase
{
    public function testFlagsWhenTheFileUrlItselfNamesNarration(): void
    {
        self::assertTrue(
            NarrationSignals::narrates('https://zon-speechbert-production.test/articles/a/full.mp3', null),
        );
    }

    public function testFlagsWhenAnAncestorAttributeDeclaresTts(): void
    {
        $audio = $this->audioIn('<div data-audio-type="tts"><p><audio></audio></p></div>');

        self::assertTrue(NarrationSignals::narrates('https://cdn.test/plain.mp3', $audio));
    }

    public function testDoesNotTreatHttpsOrAnOrdinaryAttributeAsNarration(): void
    {
        $audio = $this->audioIn('<div class="settings" data-x="chatts"><audio></audio></div>');

        self::assertFalse(NarrationSignals::narrates('https://cdn.test/audio/ep-042.mp3', $audio));
    }

    public function testAPlainPodcastFileIsNotNarration(): void
    {
        self::assertFalse(NarrationSignals::narrates('https://pub.test/episodes/ep12.mp3', null));
    }

    private function audioIn(string $html): Element
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        self::assertNotNull($document);
        $audio = $document->querySelector('audio');
        self::assertInstanceOf(Element::class, $audio);

        return $audio;
    }
}
