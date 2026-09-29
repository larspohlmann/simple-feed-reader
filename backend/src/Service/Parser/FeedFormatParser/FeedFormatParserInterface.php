<?php

declare(strict_types=1);

namespace App\Service\Parser\FeedFormatParser;

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

    public function parse(\DOMDocument $document): ParsedFeedModel;
}
