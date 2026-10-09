<?php

declare(strict_types=1);

namespace App\Service\Html\Support;

use Dom\Document;
use Dom\Element;
use Dom\Text;

/**
 * Text pasted into a <p> keeps its line breaks as newlines, which HTML collapses: a blank line between two runs
 * of the paragraph's text marks it, and every newline inside its text nodes becomes <br> (a blank-line run, two).
 */
final class PastedTextBreaks
{
    private const string BLANK_LINE_PATTERN = '/\S[ \t]*\n[ \t]*\n\s*\S/';
    private const string RAW_BLANK_LINE_PATTERN = '/\R(?:[ \t]|<[^>]*>)*\R/';
    private const array VERBATIM_ELEMENTS = ['code', 'pre', 'script', 'style', 'textarea', 'kbd', 'samp'];

    public static function inHtml(string $html): string
    {
        if (preg_match(self::RAW_BLANK_LINE_PATTERN, $html) !== 1) {
            return $html;
        }

        $document = HtmlDocumentParser::parseUtf8('<body>' . $html);
        if (self::restoreIn($document) === 0) {
            return $html;
        }

        return $document->body->innerHTML ?? $html;
    }

    /** @return int how many paragraphs got their line breaks back */
    public static function restoreIn(Document $document): int
    {
        $pastedParagraphs = array_filter(
            iterator_to_array($document->querySelectorAll('p')),
            static fn (Element $paragraph): bool => self::isPasted($paragraph),
        );
        foreach ($pastedParagraphs as $paragraph) {
            foreach (self::proseTextsIn($paragraph) as $text) {
                self::replaceWithBreaks($text, $document);
            }
        }

        return count($pastedParagraphs);
    }

    private static function isPasted(Element $paragraph): bool
    {
        $prose = implode('', array_map(static fn (Text $text): string => $text->data, self::proseTextsIn($paragraph)));

        return preg_match(self::BLANK_LINE_PATTERN, $prose) === 1;
    }

    /** @return list<Text> */
    private static function proseTextsIn(Element $element): array
    {
        $texts = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof Text) {
                $texts[] = $child;
            } elseif ($child instanceof Element && !in_array($child->localName, self::VERBATIM_ELEMENTS, true)) {
                $texts = [...$texts, ...self::proseTextsIn($child)];
            }
        }

        return $texts;
    }

    private static function replaceWithBreaks(Text $text, Document $document): void
    {
        $content = $text->data;
        $core = trim($content);
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
