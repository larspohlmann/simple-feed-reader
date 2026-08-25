<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\EdgeBoilerplateTrimmer;
use App\Service\Reader\LeadingTitleRemover;
use App\Service\Reader\ReaderBodyCleaner;
use PHPUnit\Framework\TestCase;

final class ReaderBodyCleanerTest extends TestCase
{
    private const string PROSE =
        'Ein ausreichend langer Absatz mit echtem Fliesstext, der die Schwelle '
        . 'fuer einen substantiellen Absatz sicher ueberschreitet und daher als '
        . 'echter Artikelinhalt zaehlt und nicht als Randblock behandelt wird.';

    private ReaderBodyCleaner $cleaner;

    protected function setUp(): void
    {
        $this->cleaner = new ReaderBodyCleaner(new LeadingTitleRemover(), new EdgeBoilerplateTrimmer());
    }

    public function testDropsTheLeadingDuplicateHeadingInOnePass(): void
    {
        $content = '<div><h2>My Article</h2><p>' . self::PROSE . '</p></div>';

        $result = $this->cleaner->clean($content, ['My Article']);

        self::assertStringNotContainsString('<h2>', $result);
        self::assertStringContainsString('Fliesstext', $result);
    }

    public function testTrimsTrailingEdgeBoilerplateInTheSamePass(): void
    {
        $grid = '<div class="jp-relatedposts"><a href="/a">A</a><a href="/b">B</a>'
            . '<a href="/c">C</a><a href="/d">D</a></div>';
        $content = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $grid . '</div>';

        $result = $this->cleaner->clean($content, [null]);

        self::assertStringNotContainsString('jp-relatedposts', $result);
        self::assertStringContainsString('Fliesstext', $result);
    }

    public function testRemovesTheDuplicateHeadingAndTheTrailingBoilerplateTogether(): void
    {
        $grid = '<div class="jp-relatedposts"><a href="/a">A</a><a href="/b">B</a>'
            . '<a href="/c">C</a><a href="/d">D</a></div>';
        $content = '<div><h2>My Article</h2><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . '<p>' . self::PROSE . '</p>' . $grid . '</div>';

        $result = $this->cleaner->clean($content, ['My Article']);

        self::assertStringNotContainsString('<h2>', $result);
        self::assertStringNotContainsString('jp-relatedposts', $result);
        self::assertStringContainsString('Fliesstext', $result);
    }

    public function testReturnsBlankInputUnchangedWithoutParsing(): void
    {
        // Readability output is always non-empty in the pipeline, but a body that
        // cannot be parsed must fall through untouched rather than crash the pass.
        self::assertSame('   ', $this->cleaner->clean('   ', ['My Article']));
    }
}
