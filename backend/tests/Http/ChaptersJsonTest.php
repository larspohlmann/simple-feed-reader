<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\EntryChapter;
use App\Http\ChaptersJson;
use PHPUnit\Framework\TestCase;

final class ChaptersJsonTest extends TestCase
{
    public function testShapesEachChapterAsAnArray(): void
    {
        self::assertSame(
            [['startSeconds' => 0, 'title' => 'Intro'], ['startSeconds' => 90, 'title' => 'Talk']],
            ChaptersJson::of([new EntryChapter(0, 'Intro'), new EntryChapter(90, 'Talk')]),
        );
    }

    public function testNoChaptersIsNull(): void
    {
        self::assertNull(ChaptersJson::of([]));
    }
}
