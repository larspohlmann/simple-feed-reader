<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Parser\Exception\FeedParseException;

final class AtomParser
{
    private const ATOM_NS = 'http://www.w3.org/2005/Atom';

    public function parse(\DOMDocument $document): ParsedFeed
    {
        $root = $document->documentElement;
        if ($root === null) {
            throw new FeedParseException('Atom document without root element');
        }

        $entries = [];
        foreach ($document->getElementsByTagNameNS(self::ATOM_NS, 'entry') as $entryElement) {
            $entry = $this->parseEntry($entryElement);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return new ParsedFeed(
            XmlHelper::childText($root, 'title', self::ATOM_NS),
            $this->alternateLink($root),
            XmlHelper::childText($root, 'subtitle', self::ATOM_NS),
            $entries,
        );
    }

    private function parseEntry(\DOMElement $entry): ?ParsedEntry
    {
        $title = XmlHelper::childText($entry, 'title', self::ATOM_NS);
        $link = $this->alternateLink($entry);
        if ($title === null && $link === null) {
            return null;
        }

        return new ParsedEntry(
            guid: GuidFallback::for(XmlHelper::childText($entry, 'id', self::ATOM_NS), $link, $title),
            url: $link,
            title: $title ?? '(untitled)',
            author: $this->authorName($entry),
            summary: XmlHelper::childText($entry, 'summary', self::ATOM_NS),
            contentHtml: $this->contentHtml($entry),
            publishedAt: DateParser::parse(
                XmlHelper::childText($entry, 'published', self::ATOM_NS)
                ?? XmlHelper::childText($entry, 'updated', self::ATOM_NS),
            ),
        );
    }

    private function alternateLink(\DOMElement $parent): ?string
    {
        $fallback = null;
        foreach ($parent->childNodes as $child) {
            if (
                !$child instanceof \DOMElement
                || $child->localName !== 'link'
                || $child->namespaceURI !== self::ATOM_NS
            ) {
                continue;
            }
            $href = trim($child->getAttribute('href'));
            if ($href === '') {
                continue;
            }
            $rel = $child->getAttribute('rel');
            if ($rel === 'alternate') {
                return $href;
            }
            if ($rel === '') {
                $fallback ??= $href;
            }
        }

        return $fallback;
    }

    private function authorName(\DOMElement $entry): ?string
    {
        foreach ($entry->childNodes as $child) {
            if (
                $child instanceof \DOMElement
                && $child->localName === 'author'
                && $child->namespaceURI === self::ATOM_NS
            ) {
                return XmlHelper::childText($child, 'name', self::ATOM_NS);
            }
        }

        return null;
    }

    private function contentHtml(\DOMElement $entry): ?string
    {
        foreach ($entry->childNodes as $child) {
            if (
                !$child instanceof \DOMElement
                || $child->localName !== 'content'
                || $child->namespaceURI !== self::ATOM_NS
            ) {
                continue;
            }

            if ($child->getAttribute('type') === 'xhtml') {
                $html = '';
                foreach ($child->childNodes as $inner) {
                    $html .= (string) $child->ownerDocument?->saveXML($inner);
                }
                $html = trim($html);

                return $html === '' ? null : $html;
            }

            $text = trim($child->textContent);

            return $text === '' ? null : $text;
        }

        return null;
    }
}
