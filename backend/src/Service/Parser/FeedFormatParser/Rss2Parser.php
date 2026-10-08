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
use App\Service\Parser\Support\PodcastArtwork;
use App\Service\Parser\Support\XmlHelper;
use App\Service\Text\Support\PlainText;
use App\Service\Text\Support\PlainTextBody;

final readonly class Rss2Parser implements FeedFormatParserInterface
{
    private const string CONTENT_NS = 'http://purl.org/rss/1.0/modules/content/';
    private const string DC_NS = XmlHelper::DUBLIN_CORE_NAMESPACE;
    private const string WFW_NS = 'http://wellformedweb.org/CommentAPI/';

    public function __construct(
        private FeedItemImageSelector $imageSelector,
        private ItemMediaExtractor $mediaExtractor,
    ) {
    }

    public function supports(\DOMElement $root): bool
    {
        return $root->localName === 'rss';
    }

    /** Any unprefixed <item> at any depth, as getElementsByTagName('item') found them before #1452. */
    public function isEntry(\DOMElement $element): bool
    {
        return $element->nodeName === 'item';
    }

    public function parseFeed(\DOMDocument $skeleton, array $entries): ParsedFeedModel
    {
        $channel = $skeleton->getElementsByTagName('channel')->item(0);
        if (!$channel instanceof \DOMElement) {
            throw new FeedParseException('RSS document without <channel>');
        }

        return (new ParsedFeedModel(
            PlainText::from(XmlHelper::childText($channel, 'title')),
            XmlHelper::childText($channel, 'link'),
            XmlHelper::childText($channel, 'description'),
            FeedImageExtractor::fromRss2Channel($channel),
            $entries,
        ))->withShowArtwork(PodcastArtwork::of($channel));
    }

    public function parseEntry(\DOMElement $entry): ?ParsedEntryModel
    {
        $title = XmlHelper::childText($entry, 'title');
        $link = XmlHelper::childText($entry, 'link');
        if ($title === null && $link === null) {
            return null;
        }

        $description = XmlHelper::childText($entry, 'description');
        $contentEncoded = XmlHelper::childText($entry, 'encoded', self::CONTENT_NS);

        $image = $this->imageSelector->fromRss2($entry, $contentEncoded ?? $description);
        $mediaBundle = $this->mediaExtractor->extract($entry);

        return new ParsedEntryModel(
            guid: GuidFallback::for(XmlHelper::childText($entry, 'guid'), $link, $title),
            url: $link,
            title: PlainText::from($title) ?? '(untitled)',
            author: XmlHelper::childText($entry, 'author') ?? XmlHelper::childText($entry, 'creator', self::DC_NS),
            summary: $contentEncoded !== null ? $description : null,
            contentHtml: PlainTextBody::asHtml($contentEncoded ?? $description),
            publishedAt: DateParser::parse(
                XmlHelper::childText($entry, 'pubDate') ?? XmlHelper::childText($entry, 'date', self::DC_NS),
            ),
            media: new ParsedEntryMediaModel($image, $mediaBundle),
            categories: ItemCategoryExtractor::extract($entry),
            discussion: self::discussion($entry),
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
