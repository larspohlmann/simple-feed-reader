<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\SymfonyStatusReasonPhrases;
use PHPUnit\Framework\TestCase;

final class SymfonyStatusReasonPhrasesTest extends TestCase
{
    public function testAKnownStatusHasItsStandardPhrase(): void
    {
        self::assertSame('Forbidden', new SymfonyStatusReasonPhrases()->of(403));
    }

    public function testAStatusWithoutAStandardPhraseHasNone(): void
    {
        self::assertSame('', new SymfonyStatusReasonPhrases()->of(499));
    }
}
