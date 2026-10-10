<?php

declare(strict_types=1);

namespace App\Service\Html\Support;

use Dom\HTMLDocument;

final class MetaName
{
    public static function content(HTMLDocument $document, string $name): string
    {
        return $document->querySelector(sprintf('meta[name="%s" i]', $name))?->getAttribute('content') ?? '';
    }

    private function __construct()
    {
    }
}
