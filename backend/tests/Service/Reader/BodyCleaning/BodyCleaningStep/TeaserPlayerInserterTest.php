<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\TeaserPlayerInserter;
use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;
use App\Service\Reader\Media\Model\ArticleMediaModel;
use App\Service\Reader\Media\Model\MediaCandidateModel;
use App\Service\Reader\Media\Model\MediaKind;
use App\Service\Reader\Media\Teaser\Model\TeaserPlayerModel;
use App\Service\Reader\Media\Teaser\TeaserPlayerMarkup;
use App\Tests\Support\BodyCleaningInputs;
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
        return HtmlDocumentParser::parse('<body>' . $bodyHtml . '</body>');
    }

    /** @param list<TeaserPlayerModel> $teasers */
    private function insert(HTMLDocument $document, array $teasers, ArticleMediaModel $placedMedia): void
    {
        $this->inserter->cleanIn(
            new BodyCleaningPass($document, BodyCleaningInputs::withTeasers($teasers, $placedMedia)),
        );
    }

    private function video(
        string $mediaUrl = 'https://x.test/clip.mp4',
        string $poster = 'https://x.test/still.jpg',
    ): TeaserPlayerModel {
        return new TeaserPlayerModel(
            MediaKind::Video,
            $mediaUrl,
            $poster,
            'The headline',
            'https://x.test/related.html',
        );
    }

    public function testReplacesTheOrphanThumbnailWithAPlayerCaptionAndLink(): void
    {
        $document = $this->document('<p>Prose.</p><p><img src="https://x.test/still.jpg"></p>');

        $this->insert($document, [$this->video()], ArticleMediaModel::none());

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
        $teaser = new TeaserPlayerModel(
            MediaKind::Audio,
            'https://x.test/ep.mp3',
            'https://x.test/still.jpg',
            'Head',
            null,
        );

        $this->insert($document, [$teaser], ArticleMediaModel::none());

        $html = (string) $document->saveHtml();
        self::assertStringContainsString('<audio', $html);
        self::assertStringContainsString('src="https://x.test/still.jpg"', $html);
    }

    /** A teaser the media pipeline already inserted (its URL is known) is not reconstructed again. */
    public function testSkipsATeaserAlreadyAmongTheArticleMedia(): void
    {
        $document = $this->document('<p><img src="https://x.test/still.jpg"></p>');
        $placed = new ArticleMediaModel([new MediaCandidateModel(MediaKind::Video, 'https://x.test/clip.mp4')]);

        $this->insert($document, [$this->video('https://x.test/clip.mp4')], $placed);

        self::assertStringContainsString('<img', (string) $document->saveHtml());
        self::assertStringNotContainsString('<video', (string) $document->saveHtml());
    }

    public function testLeavesAnUnmatchedOrphanUntouched(): void
    {
        $document = $this->document('<p><img src="https://x.test/other.jpg"></p>');

        $this->insert($document, [$this->video()], ArticleMediaModel::none());

        self::assertStringNotContainsString('<video', (string) $document->saveHtml());
    }

    /** tagesschau serves the body thumbnail at a different size; the CMS asset id still matches. */
    public function testMatchesAcrossSizeVariantsOfTheSameStill(): void
    {
        $uuid = '5cecefc4-18df-44da-b585-b3e16fa7d7c0';
        $body = '<p><img src="https://images.test/image/' . $uuid
            . '/AA/BB/1x1-small/konjunktur-416.jpg?width=256"></p>';
        $document = $this->document($body);
        $teaser = new TeaserPlayerModel(
            MediaKind::Video,
            'https://x.test/clip.mp4',
            'https://images.test/image/' . $uuid . '/AA/CC/16x9-1920/konjunktur-416.jpg',
            null,
            null,
        );

        $this->insert($document, [$teaser], ArticleMediaModel::none());

        self::assertStringContainsString('<video', (string) $document->saveHtml());
    }

    /** A thumbnail alone in a paragraph takes the paragraph with it, leaving no empty wrapper. */
    public function testReplacesTheWholeParagraphWhenTheThumbnailIsAlone(): void
    {
        $document = $this->document('<p><img src="https://x.test/still.jpg"></p>');

        $this->insert($document, [$this->video()], ArticleMediaModel::none());

        $html = (string) $document->saveHtml();
        self::assertStringContainsString('<figure class="reader-teaser">', $html);
        self::assertStringNotContainsString('<p>', $html);
    }

    /** A thumbnail sharing its paragraph with text is replaced in place; the paragraph stays. */
    public function testKeepsAParagraphThatHoldsMoreThanTheThumbnail(): void
    {
        $document = $this->document('<p>Around <img src="https://x.test/still.jpg"> it.</p>');

        $this->insert($document, [$this->video()], ArticleMediaModel::none());

        $html = (string) $document->saveHtml();
        self::assertStringContainsString('<p>Around <figure', $html);
        self::assertStringContainsString('it.</p>', $html);
    }

    public function testEachTeaserClaimsOneThumbnailOnly(): void
    {
        $document = $this->document(
            '<p><img src="https://x.test/still.jpg"></p><p><img src="https://x.test/still.jpg"></p>',
        );

        $this->insert($document, [$this->video()], ArticleMediaModel::none());

        self::assertSame(1, substr_count((string) $document->saveHtml(), '<video'));
        self::assertStringContainsString('<img', (string) $document->saveHtml());
    }
}
