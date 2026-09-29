<?php

declare(strict_types=1);

namespace App\Service\Reader\Support;

use App\Service\Text\Support\Whitespace;
use Dom\Element;

/**
 * The two text measurements read off a block, both taken on
 * whitespace-collapsed text: indentation between list items is markup, not
 * content, and left in it dilutes a teaser list's link share below the bar (#779).
 */
final class BlockText
{
    public static function collapsed(Element $element): string
    {
        return Whitespace::collapse($element->textContent);
    }

    /** Collapsed text length of every descendant link, the share LinkListDetector weighs. */
    public static function linkTextLength(Element $element): int
    {
        $length = 0;
        foreach ($element->getElementsByTagName('a') as $link) {
            $length += mb_strlen(self::collapsed($link));
        }

        return $length;
    }

    private function __construct()
    {
    }
}
