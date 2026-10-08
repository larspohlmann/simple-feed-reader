<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\Pass;

use App\Service\Parser\FeedFormatParser\FeedFormatParserInterface;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedFeedModel;

/** Treats every element named item as an entry and records what the stream hands it. */
final class RecordingItemParser implements FeedFormatParserInterface
{
    /** @var list<\DOMElement> */
    public array $items = [];
    public ?\DOMDocument $skeleton = null;

    public function supports(\DOMElement $root): bool
    {
        return true;
    }

    public function isEntry(\DOMElement $element, \DOMNode $parent): bool
    {
        return $element->localName === 'item';
    }

    public function parseEntry(\DOMElement $entry): ?ParsedEntryModel
    {
        $this->items[] = $entry;

        return null;
    }

    public function parseFeed(\DOMDocument $skeleton, array $entries): ParsedFeedModel
    {
        $this->skeleton = $skeleton;

        return new ParsedFeedModel(null, null, null, null, $entries);
    }
}
