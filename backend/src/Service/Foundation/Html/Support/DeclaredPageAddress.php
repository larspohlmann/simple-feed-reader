<?php

declare(strict_types=1);

namespace App\Service\Foundation\Html\Support;

use Dom\HTMLDocument;

/** The address a page declares for itself, raw: its canonical link, else its `og:url`. */
final class DeclaredPageAddress
{
    public static function in(HTMLDocument $document): ?string
    {
        return $document->querySelector('link[rel="canonical"]')?->getAttribute('href')
            ?? $document->querySelector('meta[property="og:url"]')?->getAttribute('content');
    }

    private function __construct()
    {
    }
}
