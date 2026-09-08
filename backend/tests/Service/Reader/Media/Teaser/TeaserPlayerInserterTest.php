<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Teaser;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\Teaser\TeaserPlayer;
use App\Service\Reader\Media\Teaser\TeaserPlayerInserter;
use App\Service\Reader\Media\Teaser\TeaserPlayerMarkup;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class TeaserPlayerInserterTest extends TestCase
{
    private TeaserPlayerInserter $inserter;

    protected function setUp(): void
    {
        $this->inserter = new TeaserPlayerInserter(new TeaserPlayerMarkup());
    }

    private function document(string $bodyHtml): HTMLDocument
    {
        $document = HtmlDocumentParser::parseOrNull('<body>' . $bodyHtml . '</body>');
        self::assertNotNull($document);

        return $document;
    }

    private function video(
        string $mediaUrl = 'https://x.test/clip.mp4',
        string $poster = 'https://x.test/still.jpg',
    ): TeaserPlayer {
        return new TeaserPlayer(MediaKind::Video, $mediaUrl, $poster, 'The headline', 'https://x.test/related.html');
    }

    public function testReplacesTheOrphanThumbnailWithAPlayerCaptionAndLink(): void
    {
        $document = $this->document('<p>Prose.</p><p><img src="https://x.test/still.jpg"></p>');

        $this->inserter->insert($document, [$this->video()], []);

        $html = (string) $document->saveHtml();
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('<video', $html);
        self::assertStringContainsString('poster="https://x.test/still.jpg"', $html);
        self::assertStringContainsString('src="https://x.test/clip.mp4"', $html);
        self::assertStringContainsString('href="https://x.test/related.html"', $html);
        self::assertStringContainsString('The headline', $html);
    }

    /** An audio teaser keeps its thumbnail beside the player, since <audio> shows none. */
    public function testAnAudioTeaserKeepsItsThumbnail(): void
    {
        $document = $this->document('<p><img src="https://x.test/still.jpg"></p>');
        $teaser = new TeaserPlayer(MediaKind::Audio, 'https://x.test/ep.mp3', 'https://x.test/still.jpg', 'Head', null);

        $this->inserter->insert($document, [$teaser], []);

        $html = (string) $document->saveHtml();
        self::assertStringContainsString('<audio', $html);
        self::assertStringContainsString('src="https://x.test/still.jpg"', $html);
    }

    /** A teaser the media pipeline already inserted (its URL is known) is not reconstructed again. */
    public function testSkipsATeaserAlreadyAmongTheArticleMedia(): void
    {
        $document = $this->document('<p><img src="https://x.test/still.jpg"></p>');

        $this->inserter->insert($document, [$this->video('https://x.test/clip.mp4')], ['https://x.test/clip.mp4']);

        self::assertStringContainsString('<img', (string) $document->saveHtml());
        self::assertStringNotContainsString('<video', (string) $document->saveHtml());
    }

    public function testLeavesAnUnmatchedOrphanUntouched(): void
    {
        $document = $this->document('<p><img src="https://x.test/other.jpg"></p>');

        $this->inserter->insert($document, [$this->video()], []);

        self::assertStringNotContainsString('<video', (string) $document->saveHtml());
    }

    /** tagesschau serves the body thumbnail at a different size; the CMS asset id still matches. */
    public function testMatchesAcrossSizeVariantsOfTheSameStill(): void
    {
        $uuid = '5cecefc4-18df-44da-b585-b3e16fa7d7c0';
        $body = '<p><img src="https://images.test/image/' . $uuid
            . '/AA/BB/1x1-small/konjunktur-416.jpg?width=256"></p>';
        $document = $this->document($body);
        $teaser = new TeaserPlayer(
            MediaKind::Video,
            'https://x.test/clip.mp4',
            'https://images.test/image/' . $uuid . '/AA/CC/16x9-1920/konjunktur-416.jpg',
            null,
            null,
        );

        $this->inserter->insert($document, [$teaser], []);

        self::assertStringContainsString('<video', (string) $document->saveHtml());
    }

    /** A thumbnail alone in a paragraph takes the paragraph with it, leaving no empty wrapper. */
    public function testReplacesTheWholeParagraphWhenTheThumbnailIsAlone(): void
    {
        $document = $this->document('<p><img src="https://x.test/still.jpg"></p>');

        $this->inserter->insert($document, [$this->video()], []);

        $html = (string) $document->saveHtml();
        self::assertStringContainsString('<figure class="reader-teaser">', $html);
        self::assertStringNotContainsString('<p>', $html);
    }

    /** A thumbnail sharing its paragraph with text is replaced in place; the paragraph stays. */
    public function testKeepsAParagraphThatHoldsMoreThanTheThumbnail(): void
    {
        $document = $this->document('<p>Around <img src="https://x.test/still.jpg"> it.</p>');

        $this->inserter->insert($document, [$this->video()], []);

        $html = (string) $document->saveHtml();
        self::assertStringContainsString('<p>Around <figure', $html);
        self::assertStringContainsString('it.</p>', $html);
    }

    public function testEachTeaserClaimsOneThumbnailOnly(): void
    {
        $document = $this->document(
            '<p><img src="https://x.test/still.jpg"></p><p><img src="https://x.test/still.jpg"></p>',
        );

        $this->inserter->insert($document, [$this->video()], []);

        self::assertSame(1, substr_count((string) $document->saveHtml(), '<video'));
        self::assertStringContainsString('<img', (string) $document->saveHtml());
    }
}
