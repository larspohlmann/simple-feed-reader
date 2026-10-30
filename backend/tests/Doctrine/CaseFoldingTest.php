<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\CaseFolding;
use PHPUnit\Framework\TestCase;

final class CaseFoldingTest extends TestCase
{
    public function testLowerCasesLettersBeyondAscii(): void
    {
        self::assertSame('ärger über αθηνα', CaseFolding::fold('ÄRGER Über ΑΘΗΝΑ'));
    }

    public function testAnAsciiTermNeedsNoFolding(): void
    {
        self::assertFalse(CaseFolding::isNeededFor('Berlin 2026!'));
    }

    public function testATermWithANonAsciiCharacterNeedsFolding(): void
    {
        self::assertTrue(CaseFolding::isNeededFor('Ärger'));
        self::assertTrue(CaseFolding::isNeededFor('E–Mail'));
    }
}
