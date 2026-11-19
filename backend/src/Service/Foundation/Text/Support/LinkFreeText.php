<?php

declare(strict_types=1);

namespace App\Service\Foundation\Text\Support;

final class LinkFreeText
{
    private const string WEB_LINK = '#\bhttps?://\S+#u';

    public static function stripLinks(string $text): string
    {
        return preg_replace(self::WEB_LINK, '', $text) ?? $text;
    }

    private function __construct()
    {
    }
}
