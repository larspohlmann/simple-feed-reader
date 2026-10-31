<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\PageRepair;

use App\Service\Reader\PageRepair\HiddenClassElementRemover;
use App\Tests\Support\ParsesHtml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HiddenClassElementRemoverTest extends TestCase
{
    use ParsesHtml;

    private HiddenClassElementRemover $remover;

    protected function setUp(): void
    {
        $this->remover = new HiddenClassElementRemover();
    }

    public function testRemovesTheTimeoutErrorThePageHidesByClass(): void
    {
        $result = $this->htmlAfterRemoval(
            '<p>New tune with my good mate Pogo.</p>'
            . '<div id="error__timeout" class="errorPage__inner sc-hidden"><p>Sorry! Something went wrong</p></div>'
        );

        self::assertStringNotContainsString('Something went wrong', $result);
        self::assertStringContainsString('New tune with my good mate Pogo.', $result);
    }

    /** @return iterable<string, array{string}> */
    public static function hiddenClasses(): iterable
    {
        yield 'bare' => ['hidden'];
        yield 'state prefix' => ['is-hidden'];
        yield 'utility prefix' => ['u-hidden'];
    }

    #[DataProvider('hiddenClasses')]
    public function testRemovesAnElementWithAHidingClass(string $class): void
    {
        self::assertStringNotContainsString('Gone.', $this->htmlAfterRemoval('<p class="' . $class . '">Gone.</p>'));
    }

    /** @return iterable<string, array{string}> */
    public static function visibleClasses(): iterable
    {
        yield 'shown from a breakpoint on' => ['hidden md:block'];
        yield 'shown on hover' => ['hidden group-hover:flex'];
        yield 'clipped overflow' => ['overflow-hidden'];
        yield 'hidden on one device only' => ['desktop-hidden'];
        yield 'a word containing hidden' => ['hiddenGem'];
    }

    #[DataProvider('visibleClasses')]
    public function testKeepsAnElementThatCanStillShow(string $class): void
    {
        self::assertStringContainsString('Kept.', $this->htmlAfterRemoval('<p class="' . $class . '">Kept.</p>'));
    }

    private function htmlAfterRemoval(string $bodyHtml): string
    {
        $document = $this->document('<!doctype html><html lang="en"><body>' . $bodyHtml . '</body></html>');
        $this->remover->repairIn($document);

        return $document->saveHtml();
    }
}
