<?php

declare(strict_types=1);

namespace App\Service\Reader\Support;

use App\Service\Reader\Model\LeadingBlockModel;
use App\Service\Text\Support\Whitespace;
use Dom\Element;

final class LeadingEngagementBlocks
{
    private const array BLOCK_TAGS = [
        'p', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'figcaption', 'div', 'address', 'time',
    ];

    /** @return list<LeadingBlockModel> */
    public static function in(Element $root): array
    {
        $blocks = [];
        foreach ($root->getElementsByTagName('*') as $element) {
            if (!self::isLeafTextBlock($element)) {
                continue;
            }

            $text = Whitespace::collapse($element->textContent);
            if ($text !== '') {
                $blocks[] = new LeadingBlockModel($element, $text);
            }
        }

        return $blocks;
    }

    public static function isTimeOnly(Element $element): bool
    {
        if ($element->localName === 'time') {
            return true;
        }

        $times = $element->getElementsByTagName('time');

        return $times->length === 1
            && Whitespace::collapse($element->textContent)
                === Whitespace::collapse($times->item(0)?->textContent);
    }

    private static function isLeafTextBlock(Element $element): bool
    {
        return in_array($element->localName, self::BLOCK_TAGS, true)
            && !self::hasBlockDescendant($element);
    }

    private static function hasBlockDescendant(Element $element): bool
    {
        foreach ($element->getElementsByTagName('*') as $descendant) {
            if (in_array($descendant->localName, self::BLOCK_TAGS, true)) {
                return true;
            }
        }

        return false;
    }

    private function __construct()
    {
    }
}
