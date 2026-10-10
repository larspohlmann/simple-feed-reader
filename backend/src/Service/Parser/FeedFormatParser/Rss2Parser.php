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
use App\Service\Parser\Pass\CoreElement;
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
        $channelElement = $skeleton->getElementsByTagName('channel')->item(0);
        if (!$channelElement instanceof \DOMElement) {
            throw new FeedParseException('RSS document without <channel>');
        }
        $channel = self::core($channelElement);

        return (new ParsedFeedModel(
            PlainText::from($channel->text('title')),
            $channel->text('link'),
            $channel->text('description'),
            FeedImageExtractor::fromRss2Channel($channel),
            $entries,
        ))->withShowArtwork(PodcastArtwork::of($channelElement));
    }

    public function parseEntry(\DOMElement $entry): ?ParsedEntryModel
    {
        $item = self::core($entry);
        $title = $item->text('title');
        $link = $item->text('link');
        if ($title === null && $link === null) {
            return null;
        }

        $description = self::coreOrDublinCore($item, 'description', 'description');
        $contentEncoded = XmlHelper::childText($entry, 'encoded', self::CONTENT_NS);

        $image = $this->imageSelector->fromRss2($entry, $contentEncoded ?? $description);
        $mediaBundle = $this->mediaExtractor->extract($entry);

        return new ParsedEntryModel(
            guid: GuidFallback::for($item->text('guid'), $link, $title),
            url: $link,
            title: PlainText::from($title) ?? '(untitled)',
            author: self::coreOrDublinCore($item, 'author', 'creator'),
            summary: $contentEncoded !== null ? $description : null,
            contentHtml: FeedBodyHtml::of($contentEncoded ?? $description) ?? MediaDescription::html($entry),
            publishedAt: DateParser::parse(self::coreOrDublinCore($item, 'pubDate', 'date')),
            media: new ParsedEntryMediaModel($image, $mediaBundle),
            categories: ItemCategoryExtractor::extract($item),
            discussion: self::discussion($item),
        );
    }

    /** RSS 2.0 core elements share their parent's namespace: none, or the document's default one. */
    private static function core(\DOMElement $element): CoreElement
    {
        return new CoreElement($element, $element->namespaceURI);
    }

    private static function coreOrDublinCore(CoreElement $item, string $coreName, string $dublinCoreName): ?string
    {
        return $item->text($coreName) ?? XmlHelper::childText($item->element, $dublinCoreName, self::DC_NS);
    }

    private static function discussion(CoreElement $item): Discussion
    {
        return Discussion::of(
            $item->httpUrl('comments'),
            XmlHelper::childHttpUrl($item->element, 'commentRss', self::WFW_NS),
            CommentsLoad::Manual,
        );
    }
}
