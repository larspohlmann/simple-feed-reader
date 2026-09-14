<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Repair;

use App\Service\Reader\Repair\HorizontalRuleUnwrapper;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class HorizontalRuleUnwrapperTest extends TestCase
{
    private HorizontalRuleUnwrapper $unwrapper;

    protected function setUp(): void
    {
        $this->unwrapper = new HorizontalRuleUnwrapper();
    }

    /** Substack wraps each break as <div><hr></div>; readability reads that as empty and drops it. */
    public function testPromotesARuleOutOfAnEmptyWrapperDiv(): void
    {
        $html = $this->repaired('<p>One.</p><div><hr></div><p>Two.</p>');

        self::assertStringContainsString('<p>One.</p><hr><p>Two.</p>', $html);
    }

    public function testPromotesARuleOutOfNestedEmptyWrapperDivs(): void
    {
        $html = $this->repaired('<p>One.</p><div><div><hr></div></div><p>Two.</p>');

        self::assertStringContainsString('<p>One.</p><hr><p>Two.</p>', $html);
    }

    public function testLeavesAWrapperThatCarriesOtherContent(): void
    {
        $html = $this->repaired('<div>text<hr></div>');

        self::assertStringContainsString('<div>text<hr></div>', $html);
    }

    public function testLeavesAWrapperThatHoldsAnotherElement(): void
    {
        $html = $this->repaired('<div><hr><img src="https://x.test/a.jpg" alt=""></div>');

        self::assertStringContainsString('<div><hr><img src="https://x.test/a.jpg" alt=""></div>', $html);
    }

    public function testLeavesABareRuleAlone(): void
    {
        $html = $this->repaired('<p>One.</p><hr><p>Two.</p>');

        self::assertStringContainsString('<p>One.</p><hr><p>Two.</p>', $html);
    }

    private function repaired(string $bodyHtml): string
    {
        $document = HTMLDocument::createFromString(
            '<html lang="en"><body>' . $bodyHtml . '</body></html>',
            LIBXML_NOERROR,
        );
        $this->unwrapper->repairIn($document);

        return $document->saveHtml();
    }
}
