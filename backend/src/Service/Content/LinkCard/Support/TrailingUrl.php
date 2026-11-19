<?php

declare(strict_types=1);

namespace App\Service\Content\LinkCard\Support;

use App\Service\Foundation\Html\Support\HtmlDocumentParser;
use App\Service\Foundation\Url\Support\TrackingFreeUrl;
use Dom\Element;
use Dom\Node;
use Dom\Text;

final class TrailingUrl
{
    public static function removedFrom(string $html, string $url): string
    {
        $body = HtmlDocumentParser::parseFragment($html)->body;
        if ($body === null) {
            return $html;
        }
        $last = self::lastText($body);
        if ($last === null) {
            return $html;
        }
        $kept = rtrim($last->data);
        $trailing = self::trailingForm($kept, $url);
        if ($trailing === null) {
            return $html;
        }

        $last->data = rtrim(substr($kept, 0, -\strlen($trailing)));
        if ($last->data === '') {
            self::removeEmptied($last);
        }

        return $body->innerHTML;
    }

    /** The text's ending that spells the URL, verbatim or with tracking parameters a card URL no longer carries. */
    private static function trailingForm(string $text, string $url): ?string
    {
        if (str_ends_with($text, $url)) {
            return $url;
        }
        $lastWord = preg_match('/\S+\z/u', $text, $match) === 1 ? $match[0] : '';

        $isSameUrl = TrackingFreeUrl::stripTracking($lastWord) === TrackingFreeUrl::stripTracking($url);

        return $lastWord !== '' && $isSameUrl ? $lastWord : null;
    }

    private static function lastText(Node $node): ?Text
    {
        for ($child = $node->lastChild; $child !== null; $child = $child->previousSibling) {
            $found = match (true) {
                $child instanceof Text => $child,
                $child instanceof Element => self::lastText($child),
                default => null,
            };
            if ($found !== null && trim($found->data) !== '') {
                return $found;
            }
        }

        return null;
    }

    private static function removeEmptied(Text $text): void
    {
        $previous = $text->previousSibling;
        if ($previous instanceof Element && $previous->localName === 'br') {
            $previous->remove();
        }
        $parent = $text->parentElement;
        $text->remove();
        if ($parent !== null && $parent->localName === 'p' && trim($parent->textContent ?? '') === '') {
            $parent->remove();
        }
    }

    private function __construct()
    {
    }
}
