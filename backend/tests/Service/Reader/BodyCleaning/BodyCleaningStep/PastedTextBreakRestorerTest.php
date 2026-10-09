<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Reader\BodyCleaning\BodyCleaningStep\PastedTextBreakRestorer;
use App\Tests\Support\BodyCleaningPasses;
use App\Tests\Support\ParsesHtml;
use PHPUnit\Framework\TestCase;

final class PastedTextBreakRestorerTest extends TestCase
{
    use ParsesHtml;

    public function testTurnsTheNewlinesOfPastedTextIntoBreaks(): void
    {
        $document = $this->document("<p>Intro line\n\nTracklist:\n1. One\n2. Two</p>");

        new PastedTextBreakRestorer()->cleanIn(BodyCleaningPasses::over($document));

        self::assertStringContainsString(
            '<p>Intro line<br><br>Tracklist:<br>1. One<br>2. Two</p>',
            $document->saveHtml(),
        );
    }
}
