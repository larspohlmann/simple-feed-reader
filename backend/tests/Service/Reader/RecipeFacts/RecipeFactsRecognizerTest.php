<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\RecipeFacts;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\RecipeFacts\RecipeCard;
use App\Service\Reader\RecipeFacts\RecipeFactsRecognizer;
use PHPUnit\Framework\TestCase;

final class RecipeFactsRecognizerTest extends TestCase
{
    /** @return list<RecipeCard> */
    private function recognize(string $html): array
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        self::assertNotNull($document);

        return (new RecipeFactsRecognizer())->recognize($document);
    }

    private const string WPZOOM_BLOCK =
        '<div class="recipe-card-details"><div class="details-items">'
        . '<div class="detail-item detail-item-0">'
        . '<span class="detail-item-icon oldicon"></span>'
        . '<span class="detail-item-label">Portionen</span>'
        . '<p class="detail-item-value">1</p>'
        . '<span class="detail-item-unit">Portionen</span></div>'
        . '<div class="detail-item detail-item-3">'
        . '<span class="detail-item-icon"></span>'
        . '<span class="detail-item-label">Kalorien</span>'
        . '<p class="detail-item-value">135</p>'
        . '<span class="detail-item-unit">kcal</span></div>'
        . '<div class="detail-item detail-item-8">'
        . '<span class="detail-item-icon"></span>'
        . '<span class="detail-item-label">Gesamtzeit</span>'
        . '<p class="detail-item-value">2</p>'
        . '<span class="detail-item-unit">Minuten</span></div>'
        . '</div></div>';

    public function testRecognizesWpzoomDetailItemsAsFacts(): void
    {
        $cards = $this->recognize('<body>' . self::WPZOOM_BLOCK . '</body>');

        self::assertCount(1, $cards);
        self::assertCount(3, $cards[0]->facts);
        self::assertSame('Portionen', $cards[0]->facts[0]->label);
        self::assertSame('1 Portionen', $cards[0]->facts[0]->value);
        self::assertSame('Kalorien', $cards[0]->facts[1]->label);
        self::assertSame('135 kcal', $cards[0]->facts[1]->value);
        self::assertSame('Gesamtzeit', $cards[0]->facts[2]->label);
        self::assertSame('2 Minuten', $cards[0]->facts[2]->value);
    }

    public function testCapturesTheContainerToReplace(): void
    {
        $cards = $this->recognize('<body>' . self::WPZOOM_BLOCK . '</body>');

        self::assertSame('details-items', $cards[0]->container->getAttribute('class'));
    }

    public function testDropsAnItemWithNeitherLabelNorValue(): void
    {
        $cards = $this->recognize(
            '<body><div class="details-items">'
            . '<div class="detail-item"><span class="detail-item-icon"></span></div>'
            . '<div class="detail-item"><span class="detail-item-label">Kalorien</span>'
            . '<p class="detail-item-value">135</p><span class="detail-item-unit">kcal</span></div>'
            . '</div></body>',
        );

        self::assertCount(1, $cards[0]->facts);
        self::assertSame('Kalorien', $cards[0]->facts[0]->label);
    }

    public function testKeepsAFactThatHasOnlyAValue(): void
    {
        $cards = $this->recognize(
            '<body><div class="details-items">'
            . '<div class="detail-item"><p class="detail-item-value">135</p>'
            . '<span class="detail-item-unit">kcal</span></div></div></body>',
        );

        self::assertSame('', $cards[0]->facts[0]->label);
        self::assertSame('135 kcal', $cards[0]->facts[0]->value);
    }

    public function testIgnoresMarkupWithoutARecipeFactSignature(): void
    {
        $cards = $this->recognize(
            '<body><div class="prose"><p>An ordinary paragraph, not a recipe card.</p>'
            . '<ul><li>one</li><li>two</li></ul></div></body>',
        );

        self::assertSame([], $cards);
    }

    public function testLeavesAContainerWithNoRecognisableItemsAlone(): void
    {
        $cards = $this->recognize(
            '<body><div class="details-items"><div class="unrelated">nothing here</div></div></body>',
        );

        self::assertSame([], $cards);
    }
}
