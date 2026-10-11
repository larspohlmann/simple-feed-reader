<?php

declare(strict_types=1);

namespace App\Service\LinkCard\Support;

use App\Service\Html\Support\HtmlDocumentParser;
use App\Service\Html\Support\TextNodes;
use App\Service\Text\Support\Whitespace;
use Dom\Element;
use Dom\Text;

/** A Mastodon quote: a link whose paragraph reads "RE:" before it. */
final class QuoteLine
{
    private const string MARKER = 'RE:';

    public static function removedFrom(string $html, string $url): string
    {
        $document = HtmlDocumentParser::parseFragment($html);
        foreach ($document->querySelectorAll('a[href]') as $anchor) {
            $paragraph = $anchor->closest('p');
            if ($paragraph === null || $anchor->getAttribute('href') !== $url) {
                continue;
            }
            $before = self::textNodesBefore($anchor, $paragraph);
            if (self::readsQuoteMarker($before)) {
                self::remove($anchor, $paragraph, $before);

                return $document->body->innerHTML ?? $html;
            }
        }

        return $html;
    }

    /** @param list<Text> $before */
    private static function readsQuoteMarker(array $before): bool
    {
        $text = implode('', array_map(static fn (Text $node): string => $node->data, $before));

        return Whitespace::collapse($text) === self::MARKER;
    }

    /** @return list<Text> */
    private static function textNodesBefore(Element $anchor, Element $paragraph): array
    {
        $before = [];
        foreach (TextNodes::in($paragraph) as $text) {
            if ($anchor->contains($text)) {
                break;
            }
            $before[] = $text;
        }

        return $before;
    }

    /** @param list<Text> $before */
    private static function remove(Element $anchor, Element $paragraph, array $before): void
    {
        foreach ($before as $text) {
            $text->remove();
        }
        $anchor->remove();
        if ($paragraph->firstElementChild === null && Whitespace::collapse($paragraph->textContent) === '') {
            $paragraph->remove();
        }
    }

    private function __construct()
    {
    }
}
