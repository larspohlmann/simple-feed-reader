<?php

declare(strict_types=1);

namespace App\Service\Text\Support;

final class HtmlEscape
{
    public static function text(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5);
    }

    private function __construct()
    {
    }
}
