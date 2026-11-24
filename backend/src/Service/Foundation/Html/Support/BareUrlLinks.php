<?php

declare(strict_types=1);

namespace App\Service\Foundation\Html\Support;

use App\Service\Foundation\Text\Support\LinkedPlainText;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Text;

final class BareUrlLinks
{
    private const string BARE_URL_SPLIT = '#(' . LinkedPlainText::URL . ')#iu';

    public static function linkHtml(string $html): string
    {
        $document = HtmlDocumentParser::parseFragment($html);

        return self::linkIn($document) === 0 ? $html : ($document->body->innerHTML ?? $html);
    }

    public static function linkIn(HTMLDocument $document): int
    {
        $linked = 0;
        foreach (TextNodes::in($document->body) as $text) {
            if ($text->parentElement?->closest('a, code, pre') === null) {
                $linked += self::linkInText($text, $document);
            }
        }

        return $linked;
    }

    private static function linkInText(Text $text, HTMLDocument $document): int
    {
        $pieces = preg_split(self::BARE_URL_SPLIT, $text->data, -1, \PREG_SPLIT_DELIM_CAPTURE);
        if ($pieces === false || \count($pieces) < 2) {
            return 0;
        }

        $nodes = [];
        foreach ($pieces as $index => $piece) {
            $nodes[] = $index % 2 === 1 ? self::anchor($document, $piece) : $document->createTextNode($piece);
        }
        $text->replaceWith(...$nodes);

        return intdiv(\count($pieces), 2);
    }

    private static function anchor(HTMLDocument $document, string $url): Element
    {
        $anchor = $document->createElement('a');
        $anchor->setAttribute('href', $url);
        $anchor->textContent = $url;

        return $anchor;
    }

    private function __construct()
    {
    }
}
