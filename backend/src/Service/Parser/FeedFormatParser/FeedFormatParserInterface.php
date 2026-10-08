<?php

declare(strict_types=1);

namespace App\Service\Parser\FeedFormatParser;

use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedFeedModel;

/**
 * A parser for one feed dialect (RSS 2.0, RSS 1.0, Atom 1.0/0.3). Each claims its own document root through
 * supports(), so FeedParserFactory holds no central match on element names and namespaces.
 */
interface FeedFormatParserInterface
{
    /**
     * Whether this parser handles the given document root. RSS variants match on
     * the local element name; Atom dialects narrow further by namespace.
     */
    public function supports(\DOMElement $root): bool;

    /** Asked of each element as the document streams past; $parent is the skeleton node it would join. */
    public function isEntry(\DOMElement $element, \DOMNode $parent): bool;

    public function parseEntry(\DOMElement $entry): ?ParsedEntryModel;

    /**
     * @param \DOMDocument $skeleton the whole feed document except its entries
     * @param list<ParsedEntryModel> $entries
     */
    public function parseFeed(\DOMDocument $skeleton, array $entries): ParsedFeedModel;
}
