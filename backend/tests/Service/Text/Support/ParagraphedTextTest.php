<?php

declare(strict_types=1);

namespace App\Tests\Service\Text\Support;

use App\Service\Text\Support\ParagraphedText;
use PHPUnit\Framework\TestCase;

final class ParagraphedTextTest extends TestCase
{
    public function testBlankLinesSplitParagraphsAndSingleBreaksBecomeBr(): void
    {
        $bracketed = static fn (string $line): string => '[' . $line . ']';

        self::assertSame(
            '<p>[one]<br>[two]</p><p>[three]</p>',
            ParagraphedText::asHtml(" one \r\ntwo\r\n \n\rthree\n", $bracketed),
        );
    }
}
