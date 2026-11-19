<?php

declare(strict_types=1);

namespace App\Tests\Service\Outbound\Fetch\Support;

use App\Service\Outbound\Fetch\Support\ContentTypeCharset;
use PHPUnit\Framework\TestCase;

final class ContentTypeCharsetTest extends TestCase
{
    public function testReadsTheCharsetParameter(): void
    {
        self::assertSame('windows-1252', ContentTypeCharset::parseCharset('text/html; charset=windows-1252'));
    }

    public function testUnquotesAQuotedParameterAndIgnoresParameterCase(): void
    {
        self::assertSame('Shift_JIS', ContentTypeCharset::parseCharset('text/html; CHARSET="Shift_JIS"'));
    }

    public function testIgnoresParametersAfterTheCharset(): void
    {
        self::assertSame('euc-kr', ContentTypeCharset::parseCharset('text/html; charset=euc-kr; boundary=x'));
    }

    public function testIsNullWithoutACharsetParameter(): void
    {
        self::assertNull(ContentTypeCharset::parseCharset('text/html'));
        self::assertNull(ContentTypeCharset::parseCharset('text/html; charset='));
        self::assertNull(ContentTypeCharset::parseCharset(null));
    }
}
