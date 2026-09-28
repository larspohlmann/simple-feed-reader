<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Html\HtmlDocumentParser;
use Dom\HTMLDocument;

trait ParsesHtml
{
    private function document(string $html): HTMLDocument
    {
        return HtmlDocumentParser::parse($html);
    }
}
