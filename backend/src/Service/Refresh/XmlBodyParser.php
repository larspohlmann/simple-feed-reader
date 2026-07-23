<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Feed;
use App\Service\Parser\FeedParser;
use App\Service\Parser\ParsedFeed;

/**
 * The pre-seam default every existing feed row refreshes through: RSS/Atom
 * feed documents. Wraps FeedParser's format cascade rather than duplicating
 * it, so refresh, discovery and preview keep reading feed documents through
 * the one implementation.
 */
final readonly class XmlBodyParser implements FeedBodyParserInterface
{
    public function __construct(private FeedParser $parser)
    {
    }

    public static function format(): string
    {
        return 'xml';
    }

    public function parse(string $body, Feed $feed): ParsedFeed
    {
        return $this->parser->parse($body);
    }
}
