<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\EdgeBoilerplateTrimmer;
use PHPUnit\Framework\TestCase;

final class EdgeBoilerplateTrimmerTest extends TestCase
{
    private const string PROSE =
        'Ein ausreichend langer Absatz mit echtem Fliesstext, der die Schwelle '
        . 'fuer einen substantiellen Absatz sicher ueberschreitet und daher als '
        . 'echter Artikelinhalt zaehlt und nicht als Randblock behandelt wird.';

    private EdgeBoilerplateTrimmer $trimmer;

    protected function setUp(): void
    {
        $this->trimmer = new EdgeBoilerplateTrimmer();
    }

    public function testReturnsUnparsableInputUnchanged(): void
    {
        self::assertSame('', $this->trimmer->trim(''));
    }

    public function testKeepsEverythingWhenThereIsNoSubstantialParagraph(): void
    {
        // No block clears the prose threshold, so the edge is undefined and the
        // trimmer strikes nothing — a short list of links stays intact.
        $html = '<div><ul class="related"><li><a href="/a">A</a></li>'
            . '<li><a href="/b">B</a></li><li><a href="/c">C</a></li></ul></div>';

        self::assertStringContainsString('class="related"', $this->trimmer->trim($html));
    }

    public function testKeepsABoilerplateBlockThatSitsInTheArticleMiddle(): void
    {
        // A related-links block wedged between two long paragraphs is in the
        // middle, not an edge, so it is never eligible for removal.
        $middle = '<div class="related"><h3>Related posts</h3>'
            . '<a href="/a">A</a><a href="/b">B</a><a href="/c">C</a></div>';
        $html = '<div><p>' . self::PROSE . '</p>' . $middle . '<p>' . self::PROSE . '</p></div>';

        self::assertStringContainsString('class="related"', $this->trimmer->trim($html));
    }
}
