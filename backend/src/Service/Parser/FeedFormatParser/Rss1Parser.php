<?php

declare(strict_types=1);

namespace App\Service\Parser\FeedFormatParser;

use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\ItemImageExtractor;
use App\Service\Parser\ItemMediaExtractor;
use App\Service\Parser\Model\ParsedEntryMediaModel;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedFeedModel;
use App\Service\Parser\Support\DateParser;
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
        private ItemImageExtractor $imageExtractor,
        private ItemMediaExtractor $mediaExtractor,
    ) {
    }

    public function supports(\DOMElement $root): bool
    {
        return $root->localName === 'RDF';
    }

    public function parse(\DOMDocument $document): ParsedFeedModel
    {
        $channel = $document->getElementsByTagNameNS(self::RSS1_NS, 'channel')->item(0);
        if (!$channel instanceof \DOMElement) {
            throw new FeedParseException('RSS 1.0 document without <channel>');
        }

        $entries = [];
        foreach ($document->getElementsByTagNameNS(self::RSS1_NS, 'item') as $item) {
            $entry = $this->parseItem($item);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return new ParsedFeedModel(
            PlainText::from(XmlHelper::childText($channel, 'title', self::RSS1_NS)),
            XmlHelper::childText($channel, 'link', self::RSS1_NS),
            XmlHelper::childText($channel, 'description', self::RSS1_NS),
            FeedImageExtractor::fromRss1Document($document, self::RSS1_NS),
            $entries,
        );
    }

    private function parseItem(\DOMElement $item): ?ParsedEntryModel
    {
        $title = XmlHelper::childText($item, 'title', self::RSS1_NS);
        $link = XmlHelper::childText($item, 'link', self::RSS1_NS);
        if ($title === null && $link === null) {
            return null;
        }

        $about = trim($item->getAttributeNS(self::RDF_NS, 'about'));
        $description = XmlHelper::childText($item, 'description', self::RSS1_NS);
        $contentEncoded = XmlHelper::childText($item, 'encoded', self::CONTENT_NS);
        $image = $this->imageExtractor->fromMedia($item)
            ?? $this->imageExtractor->fromCustomImageElement($item)
            ?? $this->imageExtractor->fromHtml($contentEncoded ?? $description);
        $mediaBundle = $this->mediaExtractor->extract($item);

        return new ParsedEntryModel(
            guid: GuidFallback::for($about === '' ? null : $about, $link, $title),
            url: $link ?? ($about === '' ? null : $about),
            title: PlainText::from($title) ?? '(untitled)',
            author: XmlHelper::childText($item, 'creator', self::DC_NS),
            summary: $contentEncoded !== null ? $description : null,
            contentHtml: $contentEncoded ?? $description,
            publishedAt: DateParser::parse(XmlHelper::childText($item, 'date', self::DC_NS)),
            media: new ParsedEntryMediaModel($image, $mediaBundle),
            categories: ItemCategoryExtractor::extract($item),
        );
    }
}
