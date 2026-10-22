<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Model;

use App\Service\Reader\Factory\StrictDateFormatterFactory;
use App\Service\Reader\Model\ParseOffsetUnit;
use PHPUnit\Framework\TestCase;

final class ParseOffsetUnitTest extends TestCase
{
    public function testBytesCountEveryByteOfAMultibyteCharacter(): void
    {
        self::assertSame(13, ParseOffsetUnit::Bytes->lengthOf('1. März 2026'));
    }

    public function testCodePointsCountAMultibyteCharacterOnce(): void
    {
        self::assertSame(12, ParseOffsetUnit::CodePoints->lengthOf('1. März 2026'));
    }

    public function testTheReportedUnitMeasuresAWholeParseOfANonAsciiDateExactly(): void
    {
        $germanLongDate = (new StrictDateFormatterFactory())->build('de', \IntlDateFormatter::LONG);
        $text = '7. März 2026';
        $position = 0;
        $germanLongDate->parse($text, $position);

        self::assertSame($position, ParseOffsetUnit::reportedBy($germanLongDate)->lengthOf($text));
    }
}
