<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\FetchedPageNormalizer;
use App\Service\Reader\LazyImageSources;
use PHPUnit\Framework\TestCase;

final class FetchedPageNormalizerTest extends TestCase
{
    private FetchedPageNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new FetchedPageNormalizer(new LazyImageSources());
    }

    public function testCollapsesSingleChildDivChains(): void
    {
        // The fixture is the input under test, so it keeps its `lang`-less
        // <html> instead of being edited to please the IDE.
        /** @noinspection HtmlRequiredLangAttribute */
        $html = '<html><body><div class="a"><div class="b"><div class="c"><p>Text</p></div></div></div></body></html>';

        $normalized = $this->normalizer->normalize($html);

        // The two outer wrappers are gone; the innermost div (whose child is a
        // <p>, not a <div>) survives as the direct parent of the paragraph.
        self::assertStringNotContainsString('class="a"', $normalized);
        self::assertStringNotContainsString('class="b"', $normalized);
        self::assertStringContainsString('<div class="c"><p>Text</p></div>', $normalized);
    }

    public function testKeepsDivWithMultipleElementChildren(): void
    {
        // The fixture is the input under test, so it keeps its `lang`-less
        // <html> instead of being edited to please the IDE.
        /** @noinspection HtmlRequiredLangAttribute */
        $html = '<html><body><div class="keep"><div>one</div><div>two</div></div></body></html>';

        $normalized = $this->normalizer->normalize($html);

        self::assertStringContainsString('class="keep"', $normalized);
    }

    public function testKeepsDivWithOwnText(): void
    {
        // The fixture is the input under test, so it keeps its `lang`-less
        // <html> instead of being edited to please the IDE.
        /** @noinspection HtmlRequiredLangAttribute */
        $html = '<html><body><div class="keep">intro <div>nested</div></div></body></html>';

        $normalized = $this->normalizer->normalize($html);

        self::assertStringContainsString('class="keep"', $normalized);
    }

    public function testHeadingSurvivesWrapperCollapse(): void
    {
        // The fixture is the input under test, so it keeps its `lang`-less
        // <html> instead of being edited to please the IDE.
        /** @noinspection HtmlRequiredLangAttribute */
        $html = '<html><body><div><div><h2 id="s1">Section</h2></div></div></body></html>';

        $normalized = $this->normalizer->normalize($html);

        self::assertStringContainsString('<h2 id="s1">Section</h2>', $normalized);
    }

    public function testRemovesScreenReaderOnlyElements(): void
    {
        // The fixture is the input under test, so it keeps its `lang`-less
        // <html> instead of being edited to please the IDE.
        /** @noinspection HtmlRequiredLangAttribute */
        $html = '<html><body>'
            . '<span class="visually-hidden">Image source,</span>'
            . '<span class="ssrcss-1f39n02-VisuallyHidden e16en2lz0">Image caption,</span>'
            . '<span class="sr-only">skip</span>'
            . '<p class="visible">Body</p>'
            . '</body></html>';

        $normalized = $this->normalizer->normalize($html);

        self::assertStringNotContainsString('Image source,', $normalized);
        self::assertStringNotContainsString('Image caption,', $normalized);
        self::assertStringNotContainsString('skip', $normalized);
        self::assertStringContainsString('Body', $normalized);
    }

    public function testEmptyInputIsReturnedUnchanged(): void
    {
        self::assertSame('', $this->normalizer->normalize(''));
        self::assertSame('   ', $this->normalizer->normalize('   '));
    }

    public function testUmlautsSurviveNormalization(): void
    {
        // The fixture is the input under test, so it keeps its `lang`-less
        // <html> instead of being edited to please the IDE.
        /** @noinspection HtmlRequiredLangAttribute */
        $html = '<html><body><div><div><p>Grüße from Köln</p></div></div></body></html>';

        $normalized = $this->normalizer->normalize($html);

        // The DOM round-trip encodes non-ASCII as entities; the decoded text
        // must be intact. html_entity_decode covers both representations.
        self::assertStringContainsString('Grüße from Köln', html_entity_decode($normalized));
    }

    public function testRestoresTheSourceOfALazyLoadedImage(): void
    {
        // The fixture is the input under test, so it keeps its `lang`-less
        // <html> instead of being edited to please the IDE.
        /** @noinspection HtmlRequiredLangAttribute */
        $html = '<html><body><figure><img src="data:image/gif;base64,R0lGOD"'
            . ' data-lazy-src="https://images.example.com/photo.jpg"></figure></body></html>';

        $normalized = $this->normalizer->normalize($html);

        self::assertStringContainsString('<img src="https://images.example.com/photo.jpg"', $normalized);
        self::assertStringNotContainsString('data:image', $normalized);
    }
}
