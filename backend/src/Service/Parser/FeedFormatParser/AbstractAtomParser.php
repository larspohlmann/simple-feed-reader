<?php

declare(strict_types=1);

namespace App\Service\Parser\FeedFormatParser;

use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\FeedItemImageSelector;
use App\Service\Parser\ItemMediaExtractor;
use App\Service\Parser\Model\ParsedEntryMediaModel;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedFeedModel;
use App\Service\Parser\Support\AtomDiscussion;
use App\Service\Parser\Support\DateParser;
use App\Service\Parser\Support\FeedBodyHtml;
use App\Service\Parser\Support\FeedImageExtractor;
use App\Service\Parser\Support\GuidFallback;
use App\Service\Parser\Support\ItemCategoryExtractor;
use App\Service\Parser\Support\MediaDescription;
use App\Service\Parser\Support\PodcastArtwork;
use App\Service\Parser\Support\XmlHelper;
use App\Service\Text\Support\PlainText;
use App\Service\Url\Support\AbsoluteHttpUrl;

/**
 * Shared parsing for the Atom dialects. Everything but the namespace and a
 * handful of element names is identical between Atom 1.0 and Atom 0.3, so the
 * subclasses declare only those differences and inherit the traversal here.
 */
abstract readonly class AbstractAtomParser implements FeedFormatParserInterface
{
    public function __construct(
        private FeedItemImageSelector $imageSelector,
        private ItemMediaExtractor $mediaExtractor,
    ) {
    }

    /** The single XML namespace this dialect uses throughout the document. */
    abstract protected function namespaceUri(): string;

    public function supports(\DOMElement $root): bool
    {
        return XmlHelper::isElement($root, 'feed', $this->namespaceUri());
    }

    /**
     * Entry publication-date element names, most-preferred first.
     *
     * @return list<string>
     */
    abstract protected function dateElements(): array;

    /** Feed-level description element ('subtitle' in 1.0, 'tagline' in 0.3). */
    abstract protected function descriptionElement(): string;

    /** Only the feed's own children: an <entry> nested anywhere else was never one. */
    public function isEntry(\DOMElement $element, int $depth): bool
    {
        return $depth === 1 && XmlHelper::isElement($element, 'entry', $this->namespaceUri());
    }

    public function parseFeed(\DOMDocument $skeleton, array $entries): ParsedFeedModel
    {
        $root = $skeleton->documentElement;
        if ($root === null) {
            throw new FeedParseException('Atom document without root element');
        }

        $title = XmlHelper::childText($root, 'title', $this->namespaceUri());

        // A feed in the right namespace from which we extracted nothing is a
        // broken document: fail loudly so discovery/refresh report a real error
        // rather than silently creating an empty, title-less subscription.
        if ($title === null && $entries === []) {
            throw new FeedParseException('Atom feed had neither a title nor any entries');
        }

        return (new ParsedFeedModel(
            PlainText::from($title),
            $this->alternateLink($root),
            XmlHelper::childText($root, $this->descriptionElement(), $this->namespaceUri()),
            FeedImageExtractor::fromAtomFeed($root, $this->namespaceUri()),
            $entries,
        ))->withShowArtwork(PodcastArtwork::of($root));
    }

    public function parseEntry(\DOMElement $entry): ?ParsedEntryModel
    {
        $title = XmlHelper::childText($entry, 'title', $this->namespaceUri());
        $id = XmlHelper::childText($entry, 'id', $this->namespaceUri());
        // Some WordPress Atom feeds carry the permalink only in <id>; only an absolute http(s) id may stand in,
        // since a urn:/tag: id is not fetchable.
        $link = $this->alternateLink($entry) ?? AbsoluteHttpUrl::orNull($id);
        if ($title === null && $link === null) {
            return null;
        }

        $contentHtml = $this->elementMarkup($entry, 'content');
        $image = $this->imageSelector->fromAtom(
            $entry,
            $this->namespaceUri(),
            [$contentHtml, $this->elementMarkup($entry, 'summary')],
        );
        $mediaBundle = $this->mediaExtractor->extract($entry);
        $summary = XmlHelper::childText($entry, 'summary', $this->namespaceUri());

        return new ParsedEntryModel(
            guid: GuidFallback::for($id, $link, $title),
            url: $link,
            title: PlainText::from($title) ?? '(untitled)',
            author: $this->authorName($entry),
            summary: $summary,
            contentHtml: FeedBodyHtml::of($contentHtml) ?? ($summary === null ? MediaDescription::html($entry) : null),
            publishedAt: DateParser::parse($this->firstDate($entry)),
            media: new ParsedEntryMediaModel($image, $mediaBundle),
            categories: ItemCategoryExtractor::extract($entry),
            discussion: AtomDiscussion::from($entry, $this->namespaceUri()),
            authorUrl: $this->authorUri($entry),
        );
    }

    private function authorUri(\DOMElement $entry): ?string
    {
        return AbsoluteHttpUrl::orNull($this->authorChildText($entry, 'uri'));
    }

    private function authorName(\DOMElement $entry): ?string
    {
        return $this->authorChildText($entry, 'name');
    }

    private function authorChildText(\DOMElement $entry, string $localName): ?string
    {
        $author = XmlHelper::childElement($entry, 'author', $this->namespaceUri());

        return $author === null ? null : XmlHelper::childText($author, $localName, $this->namespaceUri());
    }

    /** The first present entry date, in this dialect's preference order. */
    private function firstDate(\DOMElement $entry): ?string
    {
        foreach ($this->dateElements() as $element) {
            $value = XmlHelper::childText($entry, $element, $this->namespaceUri());
            if ($value !== null) {
                return $value;
            }
        }

        // Some Atom feeds date entries only with Dublin Core <dc:date>; dropping it would show every entry as "now".
        return XmlHelper::childText($entry, 'date', XmlHelper::DUBLIN_CORE_NAMESPACE);
    }

    private function alternateLink(\DOMElement $parent): ?string
    {
        $fallback = null;
        foreach (XmlHelper::childElements($parent, 'link', $this->namespaceUri()) as $link) {
            $href = trim($link->getAttribute('href'));
            if ($href === '') {
                continue;
            }
            $rel = $link->getAttribute('rel');
            if ($rel === 'alternate') {
                return $href;
            }
            if ($rel === '') {
                $fallback ??= $href;
            }
        }

        return $fallback;
    }

    /**
     * An Atom text construct's markup: a type="xhtml" one carries real child elements that must be serialized, every
     * other type carries text. Both forms let an <img> be found in a summary-only entry.
     */
    private function elementMarkup(\DOMElement $entry, string $localName): ?string
    {
        $element = XmlHelper::childElement($entry, $localName, $this->namespaceUri());
        if ($element === null) {
            return null;
        }
        if ($element->getAttribute('type') === 'xhtml') {
            $html = '';
            foreach ($element->childNodes as $inner) {
                $html .= $element->ownerDocument?->saveXML($inner);
            }
            $html = trim($html);

            return $html === '' ? null : $html;
        }
        $text = trim($element->textContent);

        return $text === '' ? null : $text;
    }
}
