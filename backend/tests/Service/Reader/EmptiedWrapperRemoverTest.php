<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\EmptiedWrapperRemover;
use App\Tests\Support\ParsesHtml;
use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmptiedWrapperRemoverTest extends TestCase
{
    use ParsesHtml;

    private EmptiedWrapperRemover $remover;

    protected function setUp(): void
    {
        $this->remover = new EmptiedWrapperRemover();
    }

    public function testRemovesTheNodeAndEveryWrapperItLeavesEmpty(): void
    {
        $document = $this->page('<div class="outer"><div class="inner"><p id="gone">Dek.</p></div></div><p>Story.</p>');

        $this->remover->removeWithEmptiedWrappers($this->element($document, '#gone'));

        $html = $document->saveHtml();
        self::assertStringContainsString('Story.', $html);
        self::assertStringNotContainsString('Dek.', $html);
        self::assertStringNotContainsString('inner', $html);
        self::assertStringNotContainsString('outer', $html);
    }

    public function testStopsAtTheFirstWrapperThatStillHoldsText(): void
    {
        $document = $this->page('<div class="kept">Caption <div class="inner"><p id="gone">Dek.</p></div></div>');

        $this->remover->removeWithEmptiedWrappers($this->element($document, '#gone'));

        $html = $document->saveHtml();
        self::assertStringContainsString('class="kept"', $html);
        self::assertStringNotContainsString('inner', $html);
    }

    /** An emptied section would render as an empty inset card. */
    public function testDissolvesEmptiedSectioningElements(): void
    {
        $document = $this->page(
            '<main><article><section><p id="gone">Dek.</p></section></article></main><p>Story.</p>'
        );

        $this->remover->removeWithEmptiedWrappers($this->element($document, '#gone'));

        $html = $document->saveHtml();
        self::assertStringContainsString('Story.', $html);
        self::assertStringNotContainsString('<section', $html);
        self::assertStringNotContainsString('<article', $html);
        self::assertStringNotContainsString('<main', $html);
    }

    public function testNeverRemovesTheBody(): void
    {
        $document = $this->page('<p id="gone">Dek.</p>');

        $this->remover->removeWithEmptiedWrappers($this->element($document, '#gone'));

        self::assertStringContainsString('<body></body>', $document->saveHtml());
    }

    public function testLeavesAnEmptiedElementOutsideTheBodyAlone(): void
    {
        $document = $this->document(
            '<html lang="en"><head><title id="empty"></title></head><body><p>Story.</p></body></html>'
        );

        $this->remover->removeIfEmptied($this->element($document, '#empty'));

        self::assertStringContainsString('<title id="empty"></title>', $document->saveHtml());
    }

    public function testNeverRemovesTheDocumentElement(): void
    {
        $document = $this->document('<html lang="en"><head></head><body></body></html>');

        $this->remover->removeIfEmptied($this->element($document, 'html'));

        self::assertStringContainsString('<html lang="en">', $document->saveHtml());
    }

    public function testTreatsANonBreakingSpaceAsEmpty(): void
    {
        $document = $this->page('<div class="wrapper"><p id="gone">Dek.</p>&nbsp;</div><p>Story.</p>');

        $this->remover->removeWithEmptiedWrappers($this->element($document, '#gone'));

        $html = $document->saveHtml();
        self::assertStringContainsString('Story.', $html);
        self::assertStringNotContainsString('wrapper', $html);
    }

    #[DataProvider('contentWithoutText')]
    public function testKeepsAWrapperThatStillHoldsContentWithoutText(string $content): void
    {
        $document = $this->page('<div class="wrapper"><p id="gone">Dek.</p>' . $content . '</div><p>Story.</p>');

        $this->remover->removeWithEmptiedWrappers($this->element($document, '#gone'));

        self::assertStringContainsString('class="wrapper"', $document->saveHtml());
    }

    /** @return iterable<string, array{string}> */
    public static function contentWithoutText(): iterable
    {
        yield 'image' => ['<img src="https://pub.test/photo.jpg" alt="">'];
        yield 'picture' => ['<picture><source srcset="https://pub.test/photo.webp"></picture>'];
        yield 'svg' => ['<svg viewBox="0 0 10 10"><path d="M0 0h10v10z"></path></svg>'];
        yield 'video' => ['<video src="https://pub.test/clip.mp4"></video>'];
        yield 'audio' => ['<audio src="https://pub.test/talk.mp3"></audio>'];
        yield 'iframe' => ['<iframe src="https://pub.test/embed"></iframe>'];
    }

    #[DataProvider('blankElements')]
    public function testRemovesAWrapperLeftWithOnlyABlankElement(string $blank): void
    {
        $document = $this->page('<div class="wrapper"><p id="gone">Dek.</p>' . $blank . '</div><p>Story.</p>');

        $this->remover->removeWithEmptiedWrappers($this->element($document, '#gone'));

        $html = $document->saveHtml();
        self::assertStringContainsString('Story.', $html);
        self::assertStringNotContainsString('wrapper', $html);
    }

    /** @return iterable<string, array{string}> */
    public static function blankElements(): iterable
    {
        yield 'line break' => ['<br>'];
        yield 'rule' => ['<hr>'];
        yield 'form control' => ['<input type="checkbox">'];
        yield 'orphan source' => ['<source src="https://pub.test/clip.mp4">'];
    }

    public function testKeepsAnEmptiedElementThatIsItselfAPlayer(): void
    {
        $document = $this->page('<video src="https://pub.test/clip.mp4"><p id="gone">0:00</p></video><p>Story.</p>');

        $this->remover->removeWithEmptiedWrappers($this->element($document, '#gone'));

        $html = $document->saveHtml();
        self::assertStringContainsString('<video', $html);
        self::assertStringNotContainsString('0:00', $html);
    }

    public function testRemovesAnElementThatHoldsNothingAndTheWrappersItEmpties(): void
    {
        $document = $this->page('<div class="outer"><p id="holder"> </p></div><p>Story.</p>');

        $this->remover->removeIfEmptied($this->element($document, '#holder'));

        $html = $document->saveHtml();
        self::assertStringContainsString('Story.', $html);
        self::assertStringNotContainsString('holder', $html);
        self::assertStringNotContainsString('outer', $html);
    }

    public function testKeepsAnElementThatStillHoldsText(): void
    {
        $document = $this->page('<p id="holder">Kept words.</p>');

        $this->remover->removeIfEmptied($this->element($document, '#holder'));

        self::assertStringContainsString('Kept words.', $document->saveHtml());
    }

    private function page(string $bodyHtml): HTMLDocument
    {
        return $this->document('<html lang="en"><head><title>Page</title></head><body>' . $bodyHtml . '</body></html>');
    }

    private function element(HTMLDocument $document, string $selector): Element
    {
        $element = $document->querySelector($selector);
        self::assertInstanceOf(Element::class, $element);

        return $element;
    }
}
