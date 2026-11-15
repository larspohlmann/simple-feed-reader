<?php

declare(strict_types=1);

namespace App\Tests\Service\Foundation\Html\Support;

use App\Service\Foundation\Html\Support\DeclaredPageAddress;
use App\Service\Foundation\Html\Support\HtmlDocumentParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeclaredPageAddressTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function heads(): iterable
    {
        yield 'the canonical link first' => [
            '<meta property="og:url" content="https://a.example/og">'
            . '<link rel="canonical" href="https://a.example/canonical">',
            'https://a.example/canonical',
        ];
        yield 'og:url without a canonical link' => [
            '<meta property="og:url" content="https://a.example/og">',
            'https://a.example/og',
        ];
        yield 'a canonical link without href falls back' => [
            '<link rel="canonical"><meta property="og:url" content="/og">',
            '/og',
        ];
        yield 'as declared, not resolved' => ['<link rel="canonical" href="/a?b">', '/a?b'];
        yield 'neither' => ['<title>A page</title>', null];
    }

    #[DataProvider('heads')]
    public function testReadsTheAddressAPageDeclaresForItself(string $head, ?string $expected): void
    {
        $document = HtmlDocumentParser::parse('<html><head>' . $head . '</head><body></body></html>');

        self::assertSame($expected, DeclaredPageAddress::in($document));
    }
}
