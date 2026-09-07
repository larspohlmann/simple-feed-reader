<?php

declare(strict_types=1);

namespace App\Service\Reader\RecipeFacts;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Recognizes a recipe plugin's fact block (servings / calories / total time)
 * from one rule per plugin: the shape is container-class › item-class with a
 * label/value/unit class inside each item, so a plugin is a single rule row.
 * The publisher lays the items out in a row with its own stylesheet, which the
 * sanitizer never receives; recognizing the block lets the reader relay it.
 */
final readonly class RecipeFactsRecognizer
{
    /** @var list<array{container: string, item: string, label: string, value: string, unit: string}> */
    private const array RULES = [
        [
            'container' => 'details-items',
            'item' => 'detail-item',
            'label' => 'detail-item-label',
            'value' => 'detail-item-value',
            'unit' => 'detail-item-unit',
        ],
    ];

    /** @return list<RecipeCard> */
    public function recognize(HTMLDocument $document): array
    {
        $cards = [];
        foreach (self::RULES as $rule) {
            foreach ($document->querySelectorAll('.' . $rule['container']) as $container) {
                $facts = $this->factsOf($container, $rule);
                if ($facts !== []) {
                    $cards[] = new RecipeCard($container, $facts);
                }
            }
        }

        return $cards;
    }

    /**
     * @param array{item: string, label: string, value: string, unit: string} $rule
     *
     * @return list<RecipeFact>
     */
    private function factsOf(Element $container, array $rule): array
    {
        $facts = [];
        foreach ($container->querySelectorAll('.' . $rule['item']) as $item) {
            $fact = $this->factOf($item, $rule);
            if ($fact !== null) {
                $facts[] = $fact;
            }
        }

        return $facts;
    }

    /** @param array{label: string, value: string, unit: string} $rule */
    private function factOf(Element $item, array $rule): ?RecipeFact
    {
        $label = $this->textOf($item, $rule['label']);
        $value = $this->joinValueAndUnit($this->textOf($item, $rule['value']), $this->textOf($item, $rule['unit']));
        if ($label === '' && $value === '') {
            return null;
        }

        return new RecipeFact($label, $value);
    }

    private function joinValueAndUnit(string $value, string $unit): string
    {
        return implode(' ', array_filter([$value, $unit], static fn (string $part): bool => $part !== ''));
    }

    private function textOf(Element $item, string $class): string
    {
        $element = $item->querySelector('.' . $class);

        return $element === null ? '' : trim($element->textContent ?? '');
    }
}
