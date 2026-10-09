<?php

declare(strict_types=1);

namespace App\Service\Html\Support;

use Dom\HTMLDocument;

final class MetaProperty
{
    public static function content(HTMLDocument $document, string $property): string
    {
        return $document->querySelector(sprintf('meta[property="%s" i]', $property))?->getAttribute('content') ?? '';
    }

    private function __construct()
    {
    }
}
