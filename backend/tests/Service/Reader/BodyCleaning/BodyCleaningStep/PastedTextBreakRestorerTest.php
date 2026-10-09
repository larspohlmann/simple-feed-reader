<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Reader\BodyCleaning\BodyCleaningStep\PastedTextBreakRestorer;
use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;
use App\Tests\Support\BodyCleaningInputs;
use App\Tests\Support\ParsesHtml;
use PHPUnit\Framework\TestCase;

final class PastedTextBreakRestorerTest extends TestCase
{
    use ParsesHtml;

    public function testTurnsTheNewlinesOfPastedTextIntoBreaks(): void
    {
        $result = $this->restoreIn("<p>Intro line\n\nTracklist:\n1. One\n2. Two</p>");

        self::assertStringContainsString('<p>Intro line<br><br>Tracklist:<br>1. One<br>2. Two</p>', $result);
    }

    public function testLeavesHardWrappedProseAlone(): void
    {
        $content = "<p>A sentence wrapped\nacross two lines.</p>";

        self::assertStringContainsString($content, $this->restoreIn($content));
    }

    public function testLeavesTextOutsideAParagraphAlone(): void
    {
        $content = "<div>Layout text\n\nwith a blank line</div>";

        self::assertStringContainsString($content, $this->restoreIn($content));
    }

    private function restoreIn(string $contentHtml): string
    {
        $document = $this->document($contentHtml);

        new PastedTextBreakRestorer()->cleanIn(new BodyCleaningPass($document, BodyCleaningInputs::nothingKnown()));

        return $document->saveHtml();
    }
}
