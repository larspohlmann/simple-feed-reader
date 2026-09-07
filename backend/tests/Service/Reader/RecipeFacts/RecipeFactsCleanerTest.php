<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\RecipeFacts;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\RecipeFacts\RecipeFactsCleaner;
use App\Service\Sanitize\EntrySanitizer;
use PHPUnit\Framework\TestCase;

final class RecipeFactsCleanerTest extends TestCase
{
    private function clean(string $html): string
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        self::assertNotNull($document);
        (new RecipeFactsCleaner())->cleanIn($document);

        return $document->saveHtml();
    }

    private const string WPZOOM_BLOCK =
        '<div class="recipe-card-details"><div class="details-items">'
        . '<div class="detail-item"><span class="detail-item-icon"></span>'
        . '<span class="detail-item-label">Portionen</span>'
        . '<p class="detail-item-value">1</p>'
        . '<span class="detail-item-unit">Portionen</span></div>'
        . '<div class="detail-item"><span class="detail-item-icon"></span>'
        . '<span class="detail-item-label">Kalorien</span>'
        . '<p class="detail-item-value">135</p>'
        . '<span class="detail-item-unit">kcal</span></div>'
        . '</div></div>';

    public function testReplacesTheBlockWithASemanticFactsFigure(): void
    {
        $html = $this->clean('<body><p>Intro.</p>' . self::WPZOOM_BLOCK . '<p>Outro.</p></body>');

        self::assertStringContainsString('<figure class="reader-recipe-facts">', $html);
        self::assertStringContainsString('<dt>Portionen</dt><dd>1 Portionen</dd>', $html);
        self::assertStringContainsString('<dt>Kalorien</dt><dd>135 kcal</dd>', $html);
        // The publisher's stacked block is gone.
        self::assertStringNotContainsString('detail-item', $html);
    }

    public function testKeepsSurroundingContentAndOrder(): void
    {
        $html = $this->clean('<body><p>Intro.</p>' . self::WPZOOM_BLOCK . '<p>Outro.</p></body>');

        self::assertTrue(
            strpos($html, 'Intro.') < strpos($html, 'reader-recipe-facts'),
            'the intro paragraph stays above the facts figure',
        );
        self::assertTrue(
            strpos($html, 'reader-recipe-facts') < strpos($html, 'Outro.'),
            'the outro paragraph stays below the facts figure',
        );
    }

    public function testTheFigureMarkerSurvivesTheSanitizer(): void
    {
        $clean = (new EntrySanitizer())->sanitize($this->clean('<body>' . self::WPZOOM_BLOCK . '</body>'));

        self::assertNotNull($clean);
        self::assertStringContainsString('class="reader-recipe-facts"', $clean);
        self::assertStringContainsString('<dt>Portionen</dt>', $clean);
    }

    public function testLeavesABodyWithoutARecipeCardUnchanged(): void
    {
        $html = $this->clean('<body><p>Just prose, no recipe here.</p></body>');

        self::assertStringNotContainsString('reader-recipe-facts', $html);
        self::assertStringContainsString('Just prose, no recipe here.', $html);
    }
}
