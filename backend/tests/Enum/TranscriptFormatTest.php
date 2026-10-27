<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\TranscriptFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TranscriptFormatTest extends TestCase
{
    #[DataProvider('mimeTypes')]
    public function testMapsADeclaredTypeToItsFormat(string $mimeType, ?TranscriptFormat $expected): void
    {
        self::assertSame($expected, TranscriptFormat::fromMimeType($mimeType));
    }

    /** @return iterable<string, array{string, ?TranscriptFormat}> */
    public static function mimeTypes(): iterable
    {
        yield 'vtt' => ['text/vtt', TranscriptFormat::Vtt];
        yield 'vtt with parameters and case' => [' Text/VTT; charset=utf-8', TranscriptFormat::Vtt];
        yield 'json' => ['application/json', TranscriptFormat::Json];
        yield 'subrip' => ['application/x-subrip', TranscriptFormat::Srt];
        yield 'srt' => ['application/srt', TranscriptFormat::Srt];
        yield 'html' => ['text/html', TranscriptFormat::Html];
        yield 'plain text is unknown' => ['text/plain', null];
    }

    public function testPreferenceRanksAreConsecutiveFromZero(): void
    {
        self::assertSame(
            [0, 1, 2, 3],
            array_map(
                static fn (TranscriptFormat $format): int => $format->preference(),
                [TranscriptFormat::Vtt, TranscriptFormat::Json, TranscriptFormat::Srt, TranscriptFormat::Html],
            ),
        );
    }

    public function testPrefersVttThenJsonThenSrtThenHtml(): void
    {
        $ranked = TranscriptFormat::cases();
        usort($ranked, static fn (TranscriptFormat $left, TranscriptFormat $right): int
            => $left->preference() <=> $right->preference());

        self::assertSame(
            [TranscriptFormat::Vtt, TranscriptFormat::Json, TranscriptFormat::Srt, TranscriptFormat::Html],
            $ranked,
        );
    }
}
