<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Model;

use App\Service\Reader\Media\Model\ArticleMediaModel;
use App\Service\Reader\Media\Model\MediaCandidateModel;
use App\Service\Reader\Media\Model\MediaKind;
use PHPUnit\Framework\TestCase;

final class ArticleMediaModelTest extends TestCase
{
    public function testNoneIsEmpty(): void
    {
        self::assertTrue(ArticleMediaModel::none()->isEmpty());
    }

    public function testCandidatesAreKept(): void
    {
        $media = new ArticleMediaModel([new MediaCandidateModel(MediaKind::Audio, 'https://x.test/a.mp3')]);

        self::assertFalse($media->isEmpty());
        self::assertCount(1, $media->candidates);
    }

    /**
     * A discovered embed is suppressed when the body already recovered one in
     * place, so the reader never shows the same video twice.
     */
    public function testWithoutEmbedsDropsOnlyEmbeds(): void
    {
        $media = new ArticleMediaModel([
            new MediaCandidateModel(MediaKind::Embed, 'https://www.youtube-nocookie.com/embed/aaaaaaaaaaa'),
            new MediaCandidateModel(MediaKind::Audio, 'https://x.test/a.mp3'),
            new MediaCandidateModel(MediaKind::Video, 'https://x.test/v.mp4', 'https://x.test/p.jpg'),
        ]);

        $kept = $media->withoutEmbeds()->candidates;

        self::assertCount(2, $kept);
        self::assertSame(MediaKind::Audio, $kept[0]->kind);
        self::assertSame(MediaKind::Video, $kept[1]->kind);
    }

    public function testMaxItemsIsTwenty(): void
    {
        self::assertSame(20, ArticleMediaModel::MAX_ITEMS);
    }

    public function testStreamsYieldToFiles(): void
    {
        $media = new ArticleMediaModel([
            new MediaCandidateModel(MediaKind::Stream, 'https://x.test/master.m3u8', 'https://x.test/p.jpg'),
            new MediaCandidateModel(MediaKind::Video, 'https://x.test/a.mp4', 'https://x.test/p.jpg'),
            new MediaCandidateModel(MediaKind::Audio, 'https://x.test/a.mp3'),
        ]);

        $kinds = array_map(
            static fn (MediaCandidateModel $candidate): MediaKind => $candidate->kind,
            $media->withoutRedundantStreams()->candidates,
        );

        self::assertSame([MediaKind::Video, MediaKind::Audio], $kinds);
    }

    public function testAStreamStaysWhenNoFileIsOffered(): void
    {
        $media = new ArticleMediaModel([
            new MediaCandidateModel(MediaKind::Stream, 'https://x.test/master.m3u8', 'https://x.test/p.jpg'),
        ]);

        self::assertCount(1, $media->withoutRedundantStreams()->candidates);
    }

    public function testWithAppendsAndKeepsTheCap(): void
    {
        $one = static fn (int $number): MediaCandidateModel
            => new MediaCandidateModel(MediaKind::Video, 'https://a.test/' . $number . '.mp4', 'p.jpg');
        $media = new ArticleMediaModel(array_map($one, range(1, ArticleMediaModel::MAX_ITEMS - 1)));

        $extended = $media->with([$one(98), $one(99)]);

        self::assertCount(ArticleMediaModel::MAX_ITEMS, $extended->candidates);
        self::assertSame('https://a.test/98.mp4', $extended->candidates[ArticleMediaModel::MAX_ITEMS - 1]->url);
    }

    public function testIsVideoCoversFilesAndStreams(): void
    {
        self::assertTrue(MediaKind::Video->isVideo());
        self::assertTrue(MediaKind::Stream->isVideo());
        self::assertFalse(MediaKind::Audio->isVideo());
        self::assertFalse(MediaKind::Embed->isVideo());
    }
}
