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
}
