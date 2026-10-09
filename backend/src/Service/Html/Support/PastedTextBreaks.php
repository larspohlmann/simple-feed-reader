<?php

declare(strict_types=1);

namespace App\Service\Html\Support;

use Dom\Document;
use Dom\Element;
use Dom\Text;

/**
 * Text pasted into a <p> keeps its line breaks as newlines, which HTML collapses: a blank line inside one of the
 * paragraph's text nodes marks it, and every newline in its text nodes becomes <br> (a blank-line run, two).
 */
final class PastedTextBreaks
{
    // Also the pre-parse gate over raw markup: `<` and `>` keep a blank line between block tags from costing a parse.
    private const string BLANK_LINE_PATTERN = '/[^<>\s][ \t]*(?:\r\n?|\n)[ \t]*(?:\r\n?|\n)\s*[^<>\s]/';
    private const array VERBATIM_ELEMENTS = ['code', 'pre', 'script', 'style', 'textarea', 'kbd', 'samp'];

    public static function inHtml(string $html): string
    {
        if (preg_match(self::BLANK_LINE_PATTERN, $html) !== 1) {
            return $html;
        }

        $document = HtmlDocumentParser::parseFragment($html);
        if (!self::restoreIn($document)) {
            return $html;
        }

        return $document->body->innerHTML ?? $html;
    }

    public static function restoreIn(Document $document): bool
    {
        $restored = false;
        foreach ($document->querySelectorAll('p') as $paragraph) {
            $restored = self::restoreInParagraph($paragraph, $document) || $restored;
        }

        return $restored;
    }

    private static function restoreInParagraph(Element $paragraph, Document $document): bool
    {
        if ($paragraph->closest(implode(',', self::VERBATIM_ELEMENTS)) !== null) {
            return false;
        }

        $texts = self::proseTextsIn($paragraph);
        if (!array_any($texts, self::hasBlankLine(...))) {
            return false;
        }

        foreach ($texts as $text) {
            self::replaceWithBreaks($text, $document);
        }

        return true;
    }

    private static function hasBlankLine(Text $text): bool
    {
        return preg_match(self::BLANK_LINE_PATTERN, $text->data) === 1;
    }

    /** @return list<Text> */
    private static function proseTextsIn(Element $element): array
    {
        $texts = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof Text) {
                $texts[] = $child;
            } elseif ($child instanceof Element && !in_array($child->localName, self::VERBATIM_ELEMENTS, true)) {
                array_push($texts, ...self::proseTextsIn($child));
            }
        }

        return $texts;
    }

    private static function replaceWithBreaks(Text $text, Document $document): void
    {
        $content = $text->data;
        $core = trim($content);
        if (!str_contains($core, "\n")) {
            return;
        }

        $leadingWhitespace = substr($content, 0, strlen($content) - strlen(ltrim($content)));
        $lines = self::lines($core);
        $lines[0] = $leadingWhitespace . $lines[0];
        $lines[array_key_last($lines)] .= substr($content, strlen($leadingWhitespace) + strlen($core));

        $replacements = [$document->createTextNode(array_shift($lines))];
        foreach ($lines as $line) {
            $replacements[] = $document->createElement('br');
            $replacements[] = $document->createTextNode($line);
        }
        $text->replaceWith(...$replacements);
    }

    /** @return non-empty-list<string> trimmed lines, a run of blank lines collapsed to one */
    private static function lines(string $core): array
    {
        $collapsed = preg_replace('/\n(?:[ \t]*\n)+/', "\n\n", $core) ?? $core;

        return array_map(trim(...), explode("\n", $collapsed));
    }

    private function __construct()
    {
    }
}
