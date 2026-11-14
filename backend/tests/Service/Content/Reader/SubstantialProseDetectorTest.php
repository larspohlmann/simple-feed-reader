<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\LinkListDetector;
use App\Service\Reader\SubstantialProseDetector;
use App\Tests\Support\ParsesHtml;
use Dom\Element;
use PHPUnit\Framework\TestCase;

final class SubstantialProseDetectorTest extends TestCase
{
    use ParsesHtml;

    private SubstantialProseDetector $prose;

    protected function setUp(): void
    {
        $this->prose = new SubstantialProseDetector(new LinkListDetector());
    }

    public function testTwoHundredCharactersOfProseAreSubstantial(): void
    {
        self::assertTrue($this->prose->isSubstantial($this->paragraph(str_repeat('ä', 200))));
    }

    public function testOneHundredNinetyNineCharactersAreNot(): void
    {
        self::assertFalse($this->prose->isSubstantial($this->paragraph(str_repeat('ä', 199))));
    }

    public function testProseWithAMinorityOfLinkTextIsSubstantial(): void
    {
        $paragraph = $this->paragraph(str_repeat('ä', 200) . ' <a href="https://pub.test/more">more</a>');

        self::assertTrue($this->prose->isSubstantial($paragraph));
    }

    public function testALinkDominatedBlockIsNeverSubstantial(): void
    {
        $paragraph = $this->paragraph('<a href="https://pub.test/next">' . str_repeat('ä', 300) . '</a>');

        self::assertFalse($this->prose->isSubstantial($paragraph));
    }

    private function paragraph(string $inner): Element
    {
        $paragraph = $this->document('<body><p>' . $inner . '</p></body>')->querySelector('p');
        self::assertInstanceOf(Element::class, $paragraph);

        return $paragraph;
    }
}
