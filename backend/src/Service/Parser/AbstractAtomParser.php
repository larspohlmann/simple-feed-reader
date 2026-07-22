<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Parser\Exception\FeedParseException;

/**
 * Shared parsing for the Atom dialects. Everything but the namespace and a
 * handful of element names is identical between Atom 1.0 and Atom 0.3, so the
 * subclasses declare only those differences and inherit the traversal here.
 */
abstract class AbstractAtomParser
{
    /** The single XML namespace this dialect uses throughout the document. */
    abstract protected function namespaceUri(): string;

    /**
     * Entry publication-date element names, most-preferred first.
     *
     * @return list<string>
     */
    abstract protected function dateElements(): array;

    /** Feed-level description element ('subtitle' in 1.0, 'tagline' in 0.3). */
    abstract protected function descriptionElement(): string;

    public function parse(\DOMDocument $document): ParsedFeed
    {
        $root = $document->documentElement;
        if ($root === null) {
            throw new FeedParseException('Atom document without root element');
        }

        $ns = $this->namespaceUri();
        $title = XmlHelper::childText($root, 'title', $ns);

        $entries = [];
        foreach ($root->childNodes as $child) {
            if (
                $child instanceof \DOMElement
                && $child->localName === 'entry'
                && $child->namespaceURI === $ns
            ) {
                $entry = $this->parseEntry($child, $ns);
                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }
        }

        // A feed in the right namespace from which we extracted nothing is a
        // broken document: fail loudly so discovery/refresh report a real error
        // rather than silently creating an empty, title-less subscription.
        if ($title === null && $entries === []) {
            throw new FeedParseException('Atom feed had neither a title nor any entries');
        }

        return new ParsedFeed(
            $title,
            $this->alternateLink($root, $ns),
            XmlHelper::childText($root, $this->descriptionElement(), $ns),
            $entries,
        );
    }

    private function parseEntry(\DOMElement $entry, string $ns): ?ParsedEntry
    {
        $title = XmlHelper::childText($entry, 'title', $ns);
        $link = $this->alternateLink($entry, $ns);
        if ($title === null && $link === null) {
            return null;
        }

        return new ParsedEntry(
            guid: GuidFallback::for(XmlHelper::childText($entry, 'id', $ns), $link, $title),
            url: $link,
            title: $title ?? '(untitled)',
            author: $this->authorName($entry, $ns),
            summary: XmlHelper::childText($entry, 'summary', $ns),
            contentHtml: $this->contentHtml($entry, $ns),
            publishedAt: DateParser::parse($this->firstDate($entry, $ns)),
        );
    }

    /** The first present entry date, in this dialect's preference order. */
    private function firstDate(\DOMElement $entry, string $ns): ?string
    {
        foreach ($this->dateElements() as $element) {
            $value = XmlHelper::childText($entry, $element, $ns);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function alternateLink(\DOMElement $parent, string $ns): ?string
    {
        $fallback = null;
        foreach ($parent->childNodes as $child) {
            if (
                !$child instanceof \DOMElement
                || $child->localName !== 'link'
                || $child->namespaceURI !== $ns
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

    private function authorName(\DOMElement $entry, string $ns): ?string
    {
        foreach ($entry->childNodes as $child) {
            if (
                $child instanceof \DOMElement
                && $child->localName === 'author'
                && $child->namespaceURI === $ns
            ) {
                return XmlHelper::childText($child, 'name', $ns);
            }
        }

        return null;
    }

    private function contentHtml(\DOMElement $entry, string $ns): ?string
    {
        foreach ($entry->childNodes as $child) {
            if (
                !$child instanceof \DOMElement
                || $child->localName !== 'content'
                || $child->namespaceURI !== $ns
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
