<?php

declare(strict_types=1);

namespace App\Service\Parser\FeedFormatParser;

use App\Entity\Discussion;
use App\Enum\CommentsLoad;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\FeedItemImageSelector;
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

final readonly class Rss2Parser implements FeedFormatParserInterface
{
    private const string CONTENT_NS = 'http://purl.org/rss/1.0/modules/content/';
    private const string DC_NS = XmlHelper::DUBLIN_CORE_NAMESPACE;
    private const string WFW_NS = 'http://wellformedweb.org/CommentAPI/';

    public function supports(\DOMElement $root): bool
    {
        return $root->localName === 'rss';
    }

    public function parse(\DOMDocument $document): ParsedFeedModel
    {
        $channel = $document->getElementsByTagName('channel')->item(0);
        if (!$channel instanceof \DOMElement) {
            throw new FeedParseException('RSS document without <channel>');
        }

        $entries = [];
        foreach ($document->getElementsByTagName('item') as $item) {
            $entry = $this->parseItem($item);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return new ParsedFeedModel(
            PlainText::from(XmlHelper::childText($channel, 'title')),
            XmlHelper::childText($channel, 'link'),
            XmlHelper::childText($channel, 'description'),
            FeedImageExtractor::fromRss2Channel($channel),
            $entries,
        );
    }

    private function parseItem(\DOMElement $item): ?ParsedEntryModel
    {
        $title = XmlHelper::childText($item, 'title');
        $link = XmlHelper::childText($item, 'link');
        if ($title === null && $link === null) {
            return null;
        }

        $description = XmlHelper::childText($item, 'description');
        $contentEncoded = XmlHelper::childText($item, 'encoded', self::CONTENT_NS);

        $image = FeedItemImageSelector::fromRss2($item, $contentEncoded ?? $description);
        $mediaBundle = ItemMediaExtractor::extract($item);

        return new ParsedEntryModel(
            guid: GuidFallback::for(XmlHelper::childText($item, 'guid'), $link, $title),
            url: $link,
            title: PlainText::from($title) ?? '(untitled)',
            author: XmlHelper::childText($item, 'author') ?? XmlHelper::childText($item, 'creator', self::DC_NS),
            summary: $contentEncoded !== null ? $description : null,
            contentHtml: $contentEncoded ?? $description,
            publishedAt: DateParser::parse(
                XmlHelper::childText($item, 'pubDate') ?? XmlHelper::childText($item, 'date', self::DC_NS),
            ),
            media: new ParsedEntryMediaModel($image, $mediaBundle),
            categories: ItemCategoryExtractor::extract($item),
            discussion: self::discussion($item),
        );
    }

    private static function discussion(\DOMElement $item): Discussion
    {
        return Discussion::of(
            XmlHelper::childHttpUrl($item, 'comments'),
            XmlHelper::childHttpUrl($item, 'commentRss', self::WFW_NS),
            CommentsLoad::Manual,
        );
    }
}
