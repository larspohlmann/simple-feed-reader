<?php

declare(strict_types=1);

namespace App\Service\Bluesky\Support;

use App\Service\Html\Support\HtmlDocumentParser;
use Dom\Element;
use Dom\Node;
use Dom\Text;

final class TrailingUrl
{
    public static function removedFrom(string $html, string $url): string
    {
        $body = HtmlDocumentParser::parseFragment($html)->body;
        $last = $body === null ? null : self::lastText($body);
        $kept = $last === null ? '' : rtrim($last->data);
        if ($body === null || $last === null || !str_ends_with($kept, $url)) {
            return $html;
        }

        $last->data = rtrim(substr($kept, 0, -\strlen($url)));
        if ($last->data === '') {
            self::removeEmptied($last);
        }

        return $body->innerHTML;
    }

    private static function lastText(Node $node): ?Text
    {
        for ($child = $node->lastChild; $child !== null; $child = $child->previousSibling) {
            $found = $child instanceof Element ? self::lastText($child) : null;
            if ($child instanceof Text) {
                $found = $child;
            }
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
