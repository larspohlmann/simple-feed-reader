<?php

declare(strict_types=1);

namespace App\Service\Parser;

/**
 * A parser for one feed dialect (RSS 2.0, RSS 1.0, Atom 1.0/0.3).
 *
 * Each implementation owns the knowledge of which document root it handles via
 * supports(), so FeedParserFactory can pick a parser without a central match on
 * element names and namespaces.
 */
interface FeedFormatParserInterface
{
    /**
     * Whether this parser handles the given document root. RSS variants match on
     * the local element name; Atom dialects narrow further by namespace.
     */
    public function supports(\DOMElement $root): bool;

    public function parse(\DOMDocument $document): ParsedFeed;
}
