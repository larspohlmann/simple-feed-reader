<?php

declare(strict_types=1);

namespace App\Service\Social\Share\Support;

use App\Service\Foundation\Text\Support\Whitespace;
use Dom\Element;

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

        return preg_match(self::LABEL, Whitespace::collapse($line->textContent)) === 1;
    }

    private function __construct()
    {
    }
}
