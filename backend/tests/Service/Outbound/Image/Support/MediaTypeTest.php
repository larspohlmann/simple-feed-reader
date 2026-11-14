<?php

declare(strict_types=1);

namespace App\Tests\Service\Image\Support;

use App\Service\Image\Support\MediaType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MediaTypeTest extends TestCase
{
    /** @return iterable<string, array{?string, string}> */
    public static function headers(): iterable
    {
        yield 'a bare type' => ['image/png', 'image/png'];
        yield 'parameters drop' => ['image/jpeg; charset=binary; q=1', 'image/jpeg'];
        yield 'case and blanks fold' => ['  Image/WEBP ; charset=binary', 'image/webp'];
        yield 'no header' => [null, ''];
        yield 'an empty header' => ['', ''];
    }

    #[DataProvider('headers')]
    public function testTheMediaTypeOfAContentTypeHeader(?string $header, string $expected): void
    {
        self::assertSame($expected, MediaType::of($header));
    }
}
