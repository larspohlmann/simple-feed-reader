<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaKind;
use PHPUnit\Framework\TestCase;

final class ArticleMediaTest extends TestCase
{
    public function testNoneIsEmpty(): void
    {
        self::assertTrue(ArticleMedia::none()->isEmpty());
    }

    public function testCandidatesAreKept(): void
    {
        $media = new ArticleMedia([new MediaCandidate(MediaKind::Audio, 'https://x.test/a.mp3')]);

        self::assertFalse($media->isEmpty());
        self::assertCount(1, $media->candidates);
    }

    /**
     * A discovered embed is suppressed when the body already recovered one in
     * place, so the reader never shows the same video twice.
     */
    public function testWithoutEmbedsDropsOnlyEmbeds(): void
    {
        $media = new ArticleMedia([
            new MediaCandidate(MediaKind::Embed, 'https://www.youtube-nocookie.com/embed/aaaaaaaaaaa'),
            new MediaCandidate(MediaKind::Audio, 'https://x.test/a.mp3'),
            new MediaCandidate(MediaKind::Video, 'https://x.test/v.mp4', 'https://x.test/p.jpg'),
        ]);

        $kept = $media->withoutEmbeds()->candidates;

        self::assertCount(2, $kept);
        self::assertSame(MediaKind::Audio, $kept[0]->kind);
        self::assertSame(MediaKind::Video, $kept[1]->kind);
    }

    public function testMaxItemsIsTwenty(): void
    {
        self::assertSame(20, ArticleMedia::MAX_ITEMS);
    }

    public function testStreamsYieldToFiles(): void
    {
        $media = new ArticleMedia([
            new MediaCandidate(MediaKind::Stream, 'https://x.test/master.m3u8', 'https://x.test/p.jpg'),
            new MediaCandidate(MediaKind::Video, 'https://x.test/a.mp4', 'https://x.test/p.jpg'),
            new MediaCandidate(MediaKind::Audio, 'https://x.test/a.mp3'),
        ]);

        $kinds = array_map(
            static fn (MediaCandidate $c): MediaKind => $c->kind,
            $media->withoutRedundantStreams()->candidates,
        );

        self::assertSame([MediaKind::Video, MediaKind::Audio], $kinds);
    }

    public function testAStreamStaysWhenNoFileIsOffered(): void
    {
        $media = new ArticleMedia([
            new MediaCandidate(MediaKind::Stream, 'https://x.test/master.m3u8', 'https://x.test/p.jpg'),
        ]);

        self::assertCount(1, $media->withoutRedundantStreams()->candidates);
    }

    public function testWithAppendsAndKeepsTheCap(): void
    {
        $one = static fn (int $n): MediaCandidate
            => new MediaCandidate(MediaKind::Video, 'https://a.test/' . $n . '.mp4', 'p.jpg');
        $media = new ArticleMedia(array_map($one, range(1, ArticleMedia::MAX_ITEMS - 1)));

        $extended = $media->with([$one(98), $one(99)]);

        self::assertCount(ArticleMedia::MAX_ITEMS, $extended->candidates);
        self::assertSame('https://a.test/98.mp4', $extended->candidates[ArticleMedia::MAX_ITEMS - 1]->url);
    }

    public function testIsVideoCoversFilesAndStreams(): void
    {
        self::assertTrue(MediaKind::Video->isVideo());
        self::assertTrue(MediaKind::Stream->isVideo());
        self::assertFalse(MediaKind::Audio->isVideo());
        self::assertFalse(MediaKind::Embed->isVideo());
    }
}
