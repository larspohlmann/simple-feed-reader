<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Tests\Support\FeedFormatParsers;
use PHPUnit\Framework\TestCase;

final class UndeclaredMediaPrefixTest extends TestCase
{
    public function testAnUndeclaredMediaContentOriginalWinsOverTheBodyThumbnail(): void
    {
        $xml = (string) file_get_contents(__DIR__ . '/../../Fixtures/feeds/smarticular-undeclared-media.xml');
        $feed = FeedFormatParsers::feed($xml);

        self::assertSame(
            'https://www.smarticular.net/wp-content/uploads/2026/10/broeselknoedel-rezept.jpg',
            $feed->entries[0]->media->image?->url,
        );
    }
}
