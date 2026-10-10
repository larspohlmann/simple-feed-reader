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
use App\Service\Parser\Support\EntryTitle;
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

    public function isEntry(\DOMElement $element, \DOMNode $parent): bool
    {
        $root = $element->ownerDocument?->documentElement;
        if ($root === null) {
            return false;
        }
        $core = CoreElement::inOwnNamespace($root);
        $channel = $core->isCore($element, 'item') ? $core->child('channel') : null;

        return $channel !== null && $parent->isSameNode($channel);
    }

    public function parseFeed(\DOMDocument $skeleton, array $entries): ParsedFeedModel
    {
        $root = $skeleton->documentElement;
        $channelElement = $root === null ? null : CoreElement::inOwnNamespace($root)->child('channel');
        if ($channelElement === null) {
            throw new FeedParseException('RSS document without <channel>');
        }
        $channel = CoreElement::inOwnNamespace($channelElement);

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
        $item = CoreElement::inOwnNamespace($entry);
        $title = $item->text('title');
        $link = $item->text('link');
        if ($title === null && $link === null) {
            return null;
        }

        $description = self::coreOrDublinCore($item, 'description', 'description');
        $contentEncoded = XmlHelper::childText($entry, 'encoded', self::CONTENT_NS);

        $image = $this->imageSelector->fromRss2($item, $contentEncoded ?? $description);
        $mediaBundle = $this->mediaExtractor->extract($item);

        $contentHtml = FeedBodyHtml::of($contentEncoded ?? $description) ?? MediaDescription::html($entry);
        $entryTitle = EntryTitle::of($title, $contentHtml);

        return new ParsedEntryModel(
            guid: GuidFallback::for($item->text('guid'), $link, $title),
            url: $link,
            title: $entryTitle->text,
            author: self::coreOrDublinCore($item, 'author', 'creator'),
            summary: $contentEncoded !== null ? $description : null,
            contentHtml: $contentHtml,
            publishedAt: DateParser::parse(self::coreOrDublinCore($item, 'pubDate', 'date')),
            media: new ParsedEntryMediaModel($image, $mediaBundle),
            categories: ItemCategoryExtractor::extract($item),
            discussion: self::discussion($item),
            titleDerived: $entryTitle->derived,
        );
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
