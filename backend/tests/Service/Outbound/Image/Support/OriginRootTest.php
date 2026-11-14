<?php

declare(strict_types=1);

namespace App\Tests\Service\Image\Support;

use App\Service\Image\Support\OriginRoot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OriginRootTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function urls(): iterable
    {
        yield 'path and query drop' => [
            'https://www.oxmoxhh.de/wp-content/uploads/2026/09/a-413x450.png?ver=3',
            'https://www.oxmoxhh.de/',
        ];
        yield 'port is kept' => ['http://images.example.org:8081/x.jpg', 'http://images.example.org:8081/'];
        yield 'host is lower-cased' => ['HTTPS://CDN.Example.org/x.jpg', 'https://cdn.example.org/'];
    }

    #[DataProvider('urls')]
    public function testRootOfTheImageHost(string $imageUrl, string $expected): void
    {
        self::assertSame($expected, OriginRoot::of($imageUrl));
    }
}
