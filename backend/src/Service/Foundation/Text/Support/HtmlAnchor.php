<?php

declare(strict_types=1);

namespace App\Service\Text\Support;

final class HtmlAnchor
{
    public static function to(string $href, string $text): string
    {
        return '<a href="' . HtmlEscape::text($href) . '">' . HtmlEscape::text($text) . '</a>';
    }

    private function __construct()
    {
    }
}
