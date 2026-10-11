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

    public static function isQuote(Element $anchor): bool
    {
        $paragraph = $anchor->closest('p');
        if ($paragraph === null) {
            return false;
        }

        $before = implode('', array_map(
            static fn (Text $text): string => $text->data,
            self::textNodesBefore($anchor, $paragraph),
        ));

        return Whitespace::collapse($before) === self::MARKER;
    }

    public static function removedFrom(string $html, string $url): string
    {
        $document = HtmlDocumentParser::parseFragment($html);
        foreach ($document->querySelectorAll('a[href]') as $anchor) {
            if ($anchor->getAttribute('href') === $url && self::isQuote($anchor)) {
                self::remove($anchor);

                return $document->body->innerHTML ?? $html;
            }
        }

        return $html;
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

    private static function remove(Element $anchor): void
    {
        $paragraph = $anchor->closest('p');
        if ($paragraph === null) {
            return;
        }

        foreach (self::textNodesBefore($anchor, $paragraph) as $text) {
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
