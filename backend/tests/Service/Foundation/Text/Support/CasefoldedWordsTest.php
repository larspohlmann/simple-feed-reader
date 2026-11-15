<?php

declare(strict_types=1);

namespace App\Tests\Service\Foundation\Text\Support;

use App\Service\Foundation\Text\Support\CasefoldedWords;
use PHPUnit\Framework\TestCase;

final class CasefoldedWordsTest extends TestCase
{
    public function testWordsAreCaseFoldedAndKeepTheirDiacritics(): void
    {
        self::assertSame(['äpfel', 'élu'], CasefoldedWords::of('Äpfel élu'));
        self::assertSame(['über', 'die', 'strasse'], CasefoldedWords::of('Über die Straße'));
    }

    public function testADecomposedDiacriticStaysInsideItsWord(): void
    {
        self::assertSame(['café'], CasefoldedWords::of("Cafe\u{0301}"));
    }

    public function testCompatibilityCharactersBecomePlainLetters(): void
    {
        self::assertSame(['financial'], CasefoldedWords::of('ＦＩＮＡＮＣＩＡＬ'));
    }

    public function testWordsAreSplitButNotCut(): void
    {
        self::assertSame(
            ['vereinigte', 'staaten', 'états', 'unis'],
            CasefoldedWords::of('Vereinigte Staaten, États-Unis'),
        );
    }

    public function testTextWithoutWordsHasNoWords(): void
    {
        self::assertSame([], CasefoldedWords::of(' — !! '));
    }
}
