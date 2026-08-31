<?php

declare(strict_types=1);

namespace App\Tests\Service\ReaderAudit;

use App\Service\ReaderAudit\DatabaseValue;
use App\Service\ReaderAudit\Exception\UnexpectedDatabaseValueException;
use PHPUnit\Framework\TestCase;

final class DatabaseValueTest extends TestCase
{
    public function testReadsTheIntegersDbalHandsBackAsStrings(): void
    {
        self::assertSame(7, DatabaseValue::int('7'));
        self::assertSame(7, DatabaseValue::int(7));
    }

    public function testRefusesAValueThatIsNotANumberInsteadOfSamplingZero(): void
    {
        // A renamed column arrives as null, and a silent (int) cast would draw
        // entry 0 for every row without anything failing to say so.
        $this->expectException(UnexpectedDatabaseValueException::class);
        $this->expectExceptionMessage('Expected a number, got null.');

        DatabaseValue::int(null);
    }

    public function testReadsScalarColumnsAsText(): void
    {
        self::assertSame('Titel', DatabaseValue::string('Titel'));
        self::assertSame('7', DatabaseValue::string(7));
    }

    public function testRefusesANonScalarWhereTextWasPromised(): void
    {
        $this->expectException(UnexpectedDatabaseValueException::class);
        $this->expectExceptionMessage('Expected text, got array.');

        DatabaseValue::string([]);
    }

    public function testANullableColumnKeepsItsNullButStillCheckesTheRest(): void
    {
        self::assertNull(DatabaseValue::nullableString(null));
        self::assertSame('Titel', DatabaseValue::nullableString('Titel'));
    }

    public function testRefusesANonScalarInANullableColumnToo(): void
    {
        $this->expectException(UnexpectedDatabaseValueException::class);

        DatabaseValue::nullableString([]);
    }

    public function testAnEmptyStringCountsAsAbsentBecauseAFeedStoresOneForNoImage(): void
    {
        self::assertFalse(DatabaseValue::isPresent(''));
        self::assertFalse(DatabaseValue::isPresent(null));
        self::assertTrue(DatabaseValue::isPresent('https://example.test/a.jpg'));
    }
}
