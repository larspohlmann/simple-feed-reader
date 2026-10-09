<?php

declare(strict_types=1);

namespace App\Service\Html\Support;

use Dom\Document;
use Dom\Text;

/**
 * Text pasted into a <p> keeps its line breaks as newlines, which HTML collapses: a blank line between two
 * runs of text marks it, and every newline in that text node becomes <br> (a blank-line run, two).
 */
final class PastedTextBreaks
{
    private const string BLANK_LINE_PATTERN = '/\S[ \t]*\n[ \t]*\n\s*\S/';

    public static function inHtml(string $html): string
    {
        if (preg_match(self::BLANK_LINE_PATTERN, self::normalised($html)) !== 1) {
            return $html;
        }

        $document = HtmlDocumentParser::parse('<body>' . $html);
        self::restoreIn($document);

        $body = $document->body;

        return $body === null ? $html : $body->innerHTML;
    }

    public static function restoreIn(Document $document): void
    {
        foreach ($document->querySelectorAll('p') as $paragraph) {
            foreach (iterator_to_array($paragraph->childNodes) as $child) {
                if ($child instanceof Text && self::isPasted($child)) {
                    self::replaceWithBreaks($child, $document);
                }
            }
        }
    }

    private static function isPasted(Text $text): bool
    {
        return preg_match(self::BLANK_LINE_PATTERN, self::normalised($text->data)) === 1;
    }

    private static function replaceWithBreaks(Text $text, Document $document): void
    {
        $content = self::normalised($text->data);
        $lines = self::lines(trim($content));
        $lines[0] = self::leadingWhitespace($content) . $lines[0];
        $lines[array_key_last($lines)] .= self::trailingWhitespace($content);

        $replacements = [];
        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $replacements[] = $document->createElement('br');
            }
            if ($line !== '') {
                $replacements[] = $document->createTextNode($line);
            }
        }
        $text->replaceWith(...$replacements);
    }

    /** @return non-empty-list<string> trimmed lines, a run of blank lines collapsed to one */
    private static function lines(string $core): array
    {
        $collapsed = preg_replace('/\n(?:[ \t]*\n)+/', "\n\n", $core) ?? $core;

        return array_map(trim(...), explode("\n", $collapsed));
    }

    private static function leadingWhitespace(string $text): string
    {
        return substr($text, 0, strlen($text) - strlen(ltrim($text)));
    }

    private static function trailingWhitespace(string $text): string
    {
        return substr($text, strlen(rtrim($text)));
    }

    private static function normalised(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    private function __construct()
    {
    }
}
