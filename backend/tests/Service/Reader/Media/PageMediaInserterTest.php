<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\MediaMarkup;
use App\Service\Reader\Media\PageMediaInserter;
use PHPUnit\Framework\TestCase;

final class PageMediaInserterTest extends TestCase
{
    private PageMediaInserter $inserter;

    protected function setUp(): void
    {
        $this->inserter = new PageMediaInserter(new MediaMarkup());
    }

    private function insert(string $html, ArticleMedia $media): string
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        self::assertNotNull($document);
        $this->inserter->insertInto($document, $media);

        return $document->saveHtml();
    }

    public function testPutsAudioAtTheTopAboveTheTeaser(): void
    {
        $media = new ArticleMedia([new MediaCandidate(MediaKind::Audio, 'https://x.test/a.mp3')]);

        $out = $this->insert('<body><p>Teaser</p></body>', $media);

        self::assertStringContainsString('<audio controls="" preload="none" src="https://x.test/a.mp3">', $out);
        self::assertLessThan(strpos($out, 'Teaser'), strpos($out, '<audio'));
    }

    public function testVideoCarriesItsPoster(): void
    {
        $media = new ArticleMedia([
            new MediaCandidate(MediaKind::Video, 'https://x.test/v.mp4', 'https://x.test/p.jpg'),
        ]);

        $out = $this->insert('<body><p>Teaser</p></body>', $media);

        self::assertStringContainsString('poster="https://x.test/p.jpg"', $out);
        self::assertStringContainsString('preload="none"', $out);
    }

    public function testAnEmbedBecomesTheSameLinkShapeAsAnInBodyOne(): void
    {
        $media = new ArticleMedia([new MediaCandidate(
            MediaKind::Embed,
            'https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Ftracks%2F1',
            null,
            'Listen on SoundCloud',
        )]);

        $out = $this->insert('<body><p>Teaser</p></body>', $media);

        self::assertStringContainsString('Listen on SoundCloud', $out);
        self::assertStringContainsString('w.soundcloud.com/player/', $out);
    }

    public function testEmptyMediaLeavesTheBodyAlone(): void
    {
        $out = $this->insert('<body><p>Teaser</p></body>', ArticleMedia::none());

        self::assertStringNotContainsString('<audio', $out);
        self::assertStringContainsString('Teaser', $out);
    }

    public function testKeepsSourceOrder(): void
    {
        $media = new ArticleMedia([
            new MediaCandidate(MediaKind::Audio, 'https://x.test/first.mp3'),
            new MediaCandidate(MediaKind::Audio, 'https://x.test/second.mp3'),
        ]);

        $out = $this->insert('<body><p>Teaser</p></body>', $media);

        self::assertLessThan(strpos($out, 'second.mp3'), strpos($out, 'first.mp3'));
    }
}
