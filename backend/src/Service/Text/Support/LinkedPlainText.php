<?php

declare(strict_types=1);

namespace App\Service\Text\Support;

/** Text known to carry no markup, as HTML: escaped, paragraphed, its bare URLs linked. */
final class LinkedPlainText
{
    private const string URL_PATTERN = '#(https?://[^\s<>"]+?)(?=[.,;:!?)\]]*(?:\s|$))#i';

    public static function asHtml(string $text): ?string
    {
        $normalised = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($normalised === '') {
            return null;
        }

        $paragraphs = preg_split('/\n\s*\n/', $normalised) ?: [];

        return implode('', array_map(self::paragraph(...), $paragraphs));
    }

    private static function paragraph(string $paragraph): string
    {
        $lines = array_map(static fn (string $line): string => self::linked(trim($line)), explode("\n", $paragraph));

        return '<p>' . implode('<br>', $lines) . '</p>';
    }

    private static function linked(string $line): string
    {
        $parts = preg_split(self::URL_PATTERN, $line, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [$line];
        $html = '';
        foreach ($parts as $index => $part) {
            $escaped = htmlspecialchars($part, \ENT_QUOTES | \ENT_HTML5);
            $html .= $index % 2 === 1 ? '<a href="' . $escaped . '">' . $escaped . '</a>' : $escaped;
        }

        return $html;
    }

    private function __construct()
    {
    }
}
