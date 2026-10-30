<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\SearchFolding;
use PHPUnit\Framework\TestCase;

final class SearchFoldingTest extends TestCase
{
    public function testDropsAccentsAndLowerCases(): void
    {
        self::assertSame('arger cafe zurich naive', SearchFolding::fold('ÄRGER Café Zürich naïve'));
    }

    public function testLowerCasesLettersOutsideTheLatinAlphabet(): void
    {
        self::assertSame('αθηνα', SearchFolding::fold('ΑΘΗΝΑ'));
    }

    public function testLeavesAPlainAsciiTermLowerCased(): void
    {
        self::assertSame('berlin 2026!', SearchFolding::fold('Berlin 2026!'));
    }
}
