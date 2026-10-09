<?php

declare(strict_types=1);

namespace App\Service\Parser\FeedFormatParser;

use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\FeedItemImageSelector;
use App\Service\Parser\ItemMediaExtractor;
use App\Service\Parser\Model\ParsedEntryMediaModel;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedFeedModel;
use App\Service\Parser\Support\DateParser;
use App\Service\Parser\Support\FeedBodyHtml;
use App\Service\Parser\Support\FeedImageExtractor;
use App\Service\Parser\Support\GuidFallback;
use App\Service\Parser\Support\ItemCategoryExtractor;
use App\Service\Parser\Support\XmlHelper;
use App\Service\Text\Support\PlainText;

final readonly class Rss1Parser implements FeedFormatParserInterface
{
    private const string RSS1_NS = 'http://purl.org/rss/1.0/';
    private const string RDF_NS = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
    private const string DC_NS = XmlHelper::DUBLIN_CORE_NAMESPACE;
    private const string CONTENT_NS = 'http://purl.org/rss/1.0/modules/content/';

    public function __construct(
        private FeedItemImageSelector $imageSelector,
        private ItemMediaExtractor $mediaExtractor,
    ) {
    }

    public function supports(\DOMElement $root): bool
    {
        return $root->localName === 'RDF';
    }

    public function isEntry(\DOMElement $element, int $depth): bool
    {
        return $element->localName === 'item' && $element->namespaceURI === self::RSS1_NS;
    }

    public function parseFeed(\DOMDocument $skeleton, array $entries): ParsedFeedModel
    {
        $channel = $skeleton->getElementsByTagNameNS(self::RSS1_NS, 'channel')->item(0);
        if (!$channel instanceof \DOMElement) {
            throw new FeedParseException('RSS 1.0 document without <channel>');
        }

        return new ParsedFeedModel(
            PlainText::from(XmlHelper::childText($channel, 'title', self::RSS1_NS)),
            XmlHelper::childText($channel, 'link', self::RSS1_NS),
            XmlHelper::childText($channel, 'description', self::RSS1_NS),
            FeedImageExtractor::fromRss1Document($skeleton, self::RSS1_NS),
            $entries,
        );
    }

    public function parseEntry(\DOMElement $entry): ?ParsedEntryModel
    {
        $title = XmlHelper::childText($entry, 'title', self::RSS1_NS);
        $link = XmlHelper::childText($entry, 'link', self::RSS1_NS);
        if ($title === null && $link === null) {
            return null;
        }

        $about = trim($entry->getAttributeNS(self::RDF_NS, 'about'));
        $description = XmlHelper::childText($entry, 'description', self::RSS1_NS);
        $contentEncoded = XmlHelper::childText($entry, 'encoded', self::CONTENT_NS);
        $image = $this->imageSelector->fromRss1($entry, $contentEncoded ?? $description);
        $mediaBundle = $this->mediaExtractor->extract($entry);

        return new ParsedEntryModel(
            guid: GuidFallback::for($about === '' ? null : $about, $link, $title),
            url: $link ?? ($about === '' ? null : $about),
            title: PlainText::from($title) ?? '(untitled)',
            author: XmlHelper::childText($entry, 'creator', self::DC_NS),
            summary: $contentEncoded !== null ? $description : null,
            contentHtml: FeedBodyHtml::of($contentEncoded ?? $description),
            publishedAt: DateParser::parse(XmlHelper::childText($entry, 'date', self::DC_NS)),
            media: new ParsedEntryMediaModel($image, $mediaBundle),
            categories: ItemCategoryExtractor::extract($entry),
        );
    }
}
