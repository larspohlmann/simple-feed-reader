<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\AudioPlayerMarkup;
use App\Service\Reader\Media\Model\AudioClipModel;
use App\Tests\Support\ParsesHtml;
use PHPUnit\Framework\TestCase;

final class AudioPlayerMarkupTest extends TestCase
{
    use ParsesHtml;

    public function testABareClipIsAControlledLazyPlayerWithOneSource(): void
    {
        self::assertSame(
            '<audio controls="" preload="none" src="https://x.test/a.mp3"></audio>',
            $this->html(new AudioClipModel('https://x.test/a.mp3')),
        );
    }

    public function testCarriesTheClipsTitleAndDuration(): void
    {
        self::assertSame(
            '<audio controls="" preload="none" src="https://x.test/a.mp3" title="Ohne echte Lobby"'
            . ' data-duration="2871"></audio>',
            $this->html(new AudioClipModel('https://x.test/a.mp3', 'Ohne echte Lobby', 2871)),
        );
    }

    public function testMarksNarration(): void
    {
        self::assertSame(
            '<audio controls="" preload="none" src="https://x.test/full.mp3" class="reader-narration"></audio>',
            $this->html(new AudioClipModel('https://x.test/full.mp3', null, null, true)),
        );
    }

    private function html(AudioClipModel $clip): string
    {
        $document = $this->document('<p></p>');

        return $document->saveHtml(new AudioPlayerMarkup()->element($document, $clip));
    }
}
