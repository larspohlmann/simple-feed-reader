<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\ContentTypeCharset;
use PHPUnit\Framework\TestCase;

final class ContentTypeCharsetTest extends TestCase
{
    public function testReadsTheCharsetParameter(): void
    {
        self::assertSame('windows-1252', ContentTypeCharset::of('text/html; charset=windows-1252'));
    }

    public function testUnquotesAQuotedParameterAndIgnoresParameterCase(): void
    {
        self::assertSame('Shift_JIS', ContentTypeCharset::of('text/html; CHARSET="Shift_JIS"'));
    }

    public function testIgnoresParametersAfterTheCharset(): void
    {
        self::assertSame('euc-kr', ContentTypeCharset::of('text/html; charset=euc-kr; boundary=x'));
    }

    public function testIsNullWithoutACharsetParameter(): void
    {
        self::assertNull(ContentTypeCharset::of('text/html'));
        self::assertNull(ContentTypeCharset::of('text/html; charset='));
        self::assertNull(ContentTypeCharset::of(null));
    }
}
