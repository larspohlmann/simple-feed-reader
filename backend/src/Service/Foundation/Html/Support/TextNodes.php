<?php

declare(strict_types=1);

namespace App\Service\Html\Support;

use Dom\Element;
use Dom\Node;
use Dom\Text;

final class TextNodes
{
    /**
     * @param list<string> $skippedElements local names whose text, and their descendants' text, is left out
     *
     * @return list<Text> in document order
     */
    public static function in(?Node $root, array $skippedElements = []): array
    {
        $texts = [];
        foreach ($root->childNodes ?? [] as $child) {
            if ($child instanceof Text) {
                $texts[] = $child;
            } elseif (!$child instanceof Element || !\in_array($child->localName, $skippedElements, true)) {
                array_push($texts, ...self::in($child, $skippedElements));
            }
        }

        return $texts;
    }

    private function __construct()
    {
    }
}
