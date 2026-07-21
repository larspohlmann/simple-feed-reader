<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Parser\Exception\FeedParseException;

final class Rss1Parser
{
    private const RSS1_NS = 'http://purl.org/rss/1.0/';
    private const RDF_NS = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
    private const DC_NS = 'http://purl.org/dc/elements/1.1/';
    private const CONTENT_NS = 'http://purl.org/rss/1.0/modules/content/';

    public function parse(\DOMDocument $document): ParsedFeed
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

        return new ParsedFeed(
            XmlHelper::childText($channel, 'title', self::RSS1_NS),
            XmlHelper::childText($channel, 'link', self::RSS1_NS),
            XmlHelper::childText($channel, 'description', self::RSS1_NS),
            $entries,
        );
    }

    private function parseItem(\DOMElement $item): ?ParsedEntry
    {
        $title = XmlHelper::childText($item, 'title', self::RSS1_NS);
        $link = XmlHelper::childText($item, 'link', self::RSS1_NS);
        if ($title === null && $link === null) {
            return null;
        }

        $about = trim($item->getAttributeNS(self::RDF_NS, 'about'));
        $description = XmlHelper::childText($item, 'description', self::RSS1_NS);
        $contentEncoded = XmlHelper::childText($item, 'encoded', self::CONTENT_NS);

        return new ParsedEntry(
            guid: GuidFallback::for($about === '' ? null : $about, $link, $title),
            url: $link ?? ($about === '' ? null : $about),
            title: $title ?? '(untitled)',
            author: XmlHelper::childText($item, 'creator', self::DC_NS),
            summary: $contentEncoded !== null ? $description : null,
            contentHtml: $contentEncoded ?? $description,
            publishedAt: DateParser::parse(XmlHelper::childText($item, 'date', self::DC_NS)),
        );
    }
}
