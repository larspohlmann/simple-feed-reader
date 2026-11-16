<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Model;

use App\Service\Curation\Trending\Model\WindowLinkCardModel;
use PHPUnit\Framework\TestCase;

final class WindowLinkCardModelTest extends TestCase
{
    public function testACardShapedAsASiteNamesNoStory(): void
    {
        $card = new WindowLinkCardModel(
            1,
            'hash',
            'https://example.org/',
            'Storm floods the harbour',
            null,
            'Example',
            null,
        );

        self::assertFalse($card->namesAStory());
    }

    public function testAnArticleCardNamesAStory(): void
    {
        $card = new WindowLinkCardModel(
            1,
            'hash',
            'https://example.org/2031/storm',
            'Storm floods the harbour',
            null,
            'Example',
            null,
        );

        self::assertTrue($card->namesAStory());
    }

    public function testTheSiteIsTheCardUrlsHost(): void
    {
        self::assertSame(
            'example.org',
            (new WindowLinkCardModel(1, 'hash', 'https://www.example.org/storm', 'Storm', null, 'Example', null))->site,
        );
    }
}
