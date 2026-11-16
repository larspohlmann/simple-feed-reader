<?php

declare(strict_types=1);

namespace App\Tests\Service\Foundation\Text\Support;

use App\Service\Foundation\Text\Support\CaseFold;
use PHPUnit\Framework\TestCase;

final class CaseFoldTest extends TestCase
{
    public function testFoldsTheCaseAndComposesInFormKc(): void
    {
        self::assertSame("strasse fi", CaseFold::normalized("STRA\u{1E9E}E \u{FB01}", \Normalizer::FORM_KC));
        self::assertSame("\u{E9}t\u{E9}", CaseFold::normalized("E\u{301}T\u{C9}", \Normalizer::FORM_KC));
    }

    public function testFoldsTheCaseAndDecomposesInFormKd(): void
    {
        self::assertSame("e\u{301}te\u{301}", CaseFold::normalized("\u{C9}T\u{C9}", \Normalizer::FORM_KD));
    }
}
