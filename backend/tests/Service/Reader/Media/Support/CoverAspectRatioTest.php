<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Support;

use App\Service\Reader\Media\Support\CoverAspectRatio;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoverAspectRatioTest extends TestCase
{
    public function testAShortShowsAPortraitVideoCover(): void
    {
        self::assertSame(9 / 16, CoverAspectRatio::of('https://www.youtube.com/shorts/87Ov3cS-xMs'));
    }

    #[DataProvider('unletterboxedEntryUrls')]
    public function testAnyOtherEntryShowsItsImageFileAsIs(?string $entryUrl): void
    {
        self::assertNull(CoverAspectRatio::of($entryUrl));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function unletterboxedEntryUrls(): iterable
    {
        yield 'a YouTube watch page' => ['https://www.youtube.com/watch?v=87Ov3cS-xMs'];
        yield 'an article' => ['https://example.com/shorts/87Ov3cS-xMs'];
        yield 'no url' => [null];
    }
}
