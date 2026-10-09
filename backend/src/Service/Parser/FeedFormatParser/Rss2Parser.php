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
use App\Service\Parser\Support\FeedBodyHtml;
use App\Service\Parser\Support\FeedImageExtractor;
use App\Service\Parser\Support\GuidFallback;
use App\Service\Parser\Support\ItemCategoryExtractor;
use App\Service\Parser\Support\MediaDescription;
use App\Service\Parser\Support\PodcastArtwork;
use App\Service\Parser\Support\XmlHelper;
use App\Service\Text\Support\PlainText;

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

    /** Any unprefixed <item> at any depth. */
    public function isEntry(\DOMElement $element, int $depth): bool
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
            PlainText::from(XmlHelper::childTextInOwnNamespace($channel, 'title')),
            XmlHelper::childTextInOwnNamespace($channel, 'link'),
            XmlHelper::childTextInOwnNamespace($channel, 'description'),
            FeedImageExtractor::fromRss2Channel($channel),
            $entries,
        ))->withShowArtwork(PodcastArtwork::of($channel));
    }

    public function parseEntry(\DOMElement $entry): ?ParsedEntryModel
    {
        $title = XmlHelper::childTextInOwnNamespace($entry, 'title');
        $link = XmlHelper::childTextInOwnNamespace($entry, 'link');
        if ($title === null && $link === null) {
            return null;
        }

        $description = self::coreOrDublinCore($entry, 'description', 'description');
        $contentEncoded = XmlHelper::childText($entry, 'encoded', self::CONTENT_NS);

        $image = $this->imageSelector->fromRss2($entry, $contentEncoded ?? $description);
        $mediaBundle = $this->mediaExtractor->extract($entry);

        return new ParsedEntryModel(
            guid: GuidFallback::for(XmlHelper::childTextInOwnNamespace($entry, 'guid'), $link, $title),
            url: $link,
            title: PlainText::from($title) ?? '(untitled)',
            author: self::coreOrDublinCore($entry, 'author', 'creator'),
            summary: $contentEncoded !== null ? $description : null,
            contentHtml: FeedBodyHtml::of($contentEncoded ?? $description) ?? MediaDescription::html($entry),
            publishedAt: DateParser::parse(self::coreOrDublinCore($entry, 'pubDate', 'date')),
            media: new ParsedEntryMediaModel($image, $mediaBundle),
            categories: ItemCategoryExtractor::extract($entry),
            discussion: self::discussion($entry),
        );
    }

    private static function coreOrDublinCore(\DOMElement $item, string $coreName, string $dublinCoreName): ?string
    {
        return XmlHelper::childTextInOwnNamespace($item, $coreName)
            ?? XmlHelper::childText($item, $dublinCoreName, self::DC_NS);
    }

    private static function discussion(\DOMElement $item): Discussion
    {
        return Discussion::of(
            XmlHelper::childHttpUrl($item, 'comments', $item->namespaceURI),
            XmlHelper::childHttpUrl($item, 'commentRss', self::WFW_NS),
            CommentsLoad::Manual,
        );
    }
}
