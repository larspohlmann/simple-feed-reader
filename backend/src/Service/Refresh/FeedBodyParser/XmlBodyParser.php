<?php

declare(strict_types=1);

namespace App\Service\Refresh\FeedBodyParser;

use App\Entity\Feed;
use App\Enum\SourceFormat;
use App\Service\Parser\FeedParser;
use App\Service\Parser\Model\ParsedFeedModel;

/**
 * The default every feed row refreshes through: RSS/Atom documents, read by the FeedParser cascade that discovery
 * and preview share.
 */
final readonly class XmlBodyParser implements FeedBodyParserInterface
{
    public function __construct(private FeedParser $parser)
    {
    }

    public static function format(): string
    {
        return SourceFormat::XML;
    }

    public function parse(string $body, Feed $feed): ParsedFeedModel
    {
        return $this->parser->parse($body);
    }
}
