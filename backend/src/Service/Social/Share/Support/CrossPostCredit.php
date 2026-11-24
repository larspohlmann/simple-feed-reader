<?php

declare(strict_types=1);

namespace App\Service\Social\Share\Support;

use App\Service\Foundation\Html\Support\TextNodes;
use App\Service\Foundation\Text\Support\Whitespace;
use Dom\Element;
use Dom\Text;

/** A cross-post's credit: the first link of a paragraph that opens "cross-posted from", naming the original post. */
final class CrossPostCredit
{
    private const string LABEL = '/^cross[\s-]?(?:ge)?posted\b/iu';
    private const string LINE = 'p, h1, h2, h3, h4, h5, h6';

    public static function isCredit(Element $anchor): bool
    {
        $line = $anchor->closest(self::LINE);
        if ($line === null || $line->querySelector('a[href]') !== $anchor) {
            return false;
        }

        return preg_match(self::LABEL, self::readTextBefore($anchor, $line)) === 1;
    }

    private static function readTextBefore(Element $anchor, Element $line): string
    {
        $before = '';
        foreach (TextNodes::in($line) as $text) {
            if ($anchor->contains($text)) {
                break;
            }
            $before .= $text->data;
        }

        return Whitespace::collapse($before);
    }

    private function __construct()
    {
    }
}
