<?php

declare(strict_types=1);

namespace App\Service\Parser\FeedFormatParser;

use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\FeedItemImageSelector;
use App\Service\Parser\ItemMediaExtractor;
use App\Service\Parser\Model\FeedNamespace;
use App\Service\Parser\Model\ParsedEntryMediaModel;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedFeedModel;
use App\Service\Parser\Pass\CoreElement;
use App\Service\Parser\Support\DateParser;
use App\Service\Parser\Support\EntryTitle;
use App\Service\Parser\Support\FeedBodyHtml;
use App\Service\Parser\Support\FeedImageExtractor;
use App\Service\Parser\Support\GuidFallback;
use App\Service\Parser\Support\ItemCategoryExtractor;
use App\Service\Parser\Support\XmlHelper;
use App\Service\Text\Support\PlainText;

final readonly class Rss1Parser implements FeedFormatParserInterface
{
    public function __construct(
        private FeedItemImageSelector $imageSelector,
        private ItemMediaExtractor $mediaExtractor,
    ) {
    }

    public function supports(\DOMElement $root): bool
    {
        return XmlHelper::isElement($root, 'RDF', FeedNamespace::Rdf->value);
    }

    public function isEntry(\DOMElement $element, \DOMNode $parent): bool
    {
        return XmlHelper::isRoot($parent) && XmlHelper::isElement($element, 'item', FeedNamespace::Rss1->value);
    }

    public function parseFeed(\DOMDocument $skeleton, array $entries): ParsedFeedModel
    {
        $root = $skeleton->documentElement === null ? null : self::core($skeleton->documentElement);
        $channelElement = $root?->child('channel');
        if ($root === null || $channelElement === null) {
            throw new FeedParseException('RSS 1.0 document without <channel>');
        }
        $channel = $root->at($channelElement);

        return new ParsedFeedModel(
            PlainText::from($channel->text('title')),
            $channel->text('link'),
            $channel->text('description'),
            FeedImageExtractor::fromRss1Root($root),
            $entries,
        );
    }

    public function parseEntry(\DOMElement $entry): ?ParsedEntryModel
    {
        $item = self::core($entry);
        $title = $item->text('title');
        $link = $item->text('link');
        if ($title === null && $link === null) {
            return null;
        }

        $about = self::about($entry);
        $description = $item->text('description');
        $dublinCore = $item->in(FeedNamespace::DublinCore);
        $contentEncoded = $item->in(FeedNamespace::Content)->text('encoded');
        $image = $this->imageSelector->fromRss1($item, $contentEncoded ?? $description);
        $mediaBundle = $this->mediaExtractor->extract($item);

        $contentHtml = FeedBodyHtml::of($contentEncoded ?? $description);
        $entryTitle = EntryTitle::of($title, $contentHtml);

        return new ParsedEntryModel(
            guid: GuidFallback::for($about, $link, $title),
            url: $link ?? $about,
            title: $entryTitle->text,
            author: $dublinCore->text('creator'),
            summary: $contentEncoded !== null ? $description : null,
            contentHtml: $contentHtml,
            publishedAt: DateParser::parse($dublinCore->text('date')),
            media: new ParsedEntryMediaModel($image, $mediaBundle),
            categories: ItemCategoryExtractor::extract($item),
            titleDerived: $entryTitle->derived,
        );
    }

    private static function core(\DOMElement $element): CoreElement
    {
        return new CoreElement($element, FeedNamespace::Rss1->value);
    }

    private static function about(\DOMElement $entry): ?string
    {
        $about = trim($entry->getAttributeNS(FeedNamespace::Rdf->value, 'about'));

        return $about === '' ? null : $about;
    }
}
