<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Ingest\Support;

use App\Service\Foundation\Html\Support\HtmlDocumentParser;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;
use Dom\Element;
use Dom\Text;

final class LemmyLinkedUrl
{
    public static function parse(?string $bodyHtml): ?string
    {
        if (preg_match('#^\s*(?:<p\b[^>]*>\s*)?<a\b#i', (string) $bodyHtml) !== 1) {
            return null;
        }
        $body = HtmlDocumentParser::parseFragment((string) $bodyHtml)->body;
        if ($body === null) {
            return null;
        }
        foreach ($body->childNodes as $node) {
            if ($node instanceof Text && trim($node->data) === '') {
                continue;
            }

            return $node instanceof Element ? self::readLeadingElement($node) : null;
        }

        return null;
    }

    private static function readLeadingElement(Element $element): ?string
    {
        if ($element->localName === 'a') {
            return self::readSelfLabelledHref($element);
        }
        if ($element->localName !== 'p' || $element->childElementCount !== 1) {
            return null;
        }
        $anchor = $element->firstElementChild;
        $isOnlyContent = trim((string) $element->textContent) === trim((string) $anchor?->textContent);

        return $anchor?->localName === 'a' && $isOnlyContent ? self::readSelfLabelledHref($anchor) : null;
    }

    private static function readSelfLabelledHref(Element $anchor): ?string
    {
        $href = $anchor->getAttribute('href');
        if ($href === null || trim((string) $anchor->textContent) !== $href) {
            return null;
        }

        return AbsoluteHttpUrl::parseHostAndPath($href) === null ? null : $href;
    }

    private function __construct()
    {
    }
}
