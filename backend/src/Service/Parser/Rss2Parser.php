<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Parser\Exception\FeedParseException;

final class Rss2Parser
{
    private const CONTENT_NS = 'http://purl.org/rss/1.0/modules/content/';
    private const DC_NS = 'http://purl.org/dc/elements/1.1/';

    public function parse(\DOMDocument $document): ParsedFeed
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

        return new ParsedFeed(
            XmlHelper::childText($channel, 'title'),
            XmlHelper::childText($channel, 'link'),
            XmlHelper::childText($channel, 'description'),
            $entries,
        );
    }

    private function parseItem(\DOMElement $item): ?ParsedEntry
    {
        $title = XmlHelper::childText($item, 'title');
        $link = XmlHelper::childText($item, 'link');
        if ($title === null && $link === null) {
            return null;
        }

        $description = XmlHelper::childText($item, 'description');
        $contentEncoded = XmlHelper::childText($item, 'encoded', self::CONTENT_NS);

        $image = ItemImageExtractor::fromMedia($item)
            ?? ItemImageExtractor::fromRssEnclosure($item)
            ?? ItemImageExtractor::fromHtml($contentEncoded ?? $description);

        return new ParsedEntry(
            guid: GuidFallback::for(XmlHelper::childText($item, 'guid'), $link, $title),
            url: $link,
            title: $title ?? '(untitled)',
            author: XmlHelper::childText($item, 'author') ?? XmlHelper::childText($item, 'creator', self::DC_NS),
            summary: $contentEncoded !== null ? $description : null,
            contentHtml: $contentEncoded ?? $description,
            publishedAt: DateParser::parse(
                XmlHelper::childText($item, 'pubDate') ?? XmlHelper::childText($item, 'date', self::DC_NS),
            ),
            imageUrl: $image,
        );
    }
}
