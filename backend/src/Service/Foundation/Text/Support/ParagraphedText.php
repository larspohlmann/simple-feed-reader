<?php

declare(strict_types=1);

namespace App\Service\Text\Support;

final class ParagraphedText
{
    /**
     * @param \Closure(string): string $lineAsHtml
     */
    public static function asHtml(string $text, \Closure $lineAsHtml): string
    {
        $paragraphs = preg_split('/\n\s*\n/', trim(str_replace(["\r\n", "\r"], "\n", $text))) ?: [];

        return implode('', array_map(
            static fn (string $paragraph): string => self::paragraph($paragraph, $lineAsHtml),
            $paragraphs,
        ));
    }

    /**
     * @param \Closure(string): string $lineAsHtml
     */
    private static function paragraph(string $paragraph, \Closure $lineAsHtml): string
    {
        $lines = array_map(static fn (string $line): string => $lineAsHtml(trim($line)), explode("\n", $paragraph));

        return '<p>' . implode('<br>', $lines) . '</p>';
    }

    private function __construct()
    {
    }
}
