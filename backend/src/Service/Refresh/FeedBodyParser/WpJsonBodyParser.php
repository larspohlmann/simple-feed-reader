<?php

declare(strict_types=1);

namespace App\Service\Refresh\FeedBodyParser;

use App\Entity\Feed;
use App\Enum\SourceFormat;
use App\Service\Parser\Model\ParsedFeedModel;
use App\Service\Parser\WordPressJsonParser;

/**
 * Refresh strategy for a WordPress REST posts endpoint, through the WordPressJsonParser the subscribe preview uses
 * too. Its failures are FeedParseException, so the usual failure handling applies.
 */
final readonly class WpJsonBodyParser implements FeedBodyParserInterface
{
    public function __construct(private WordPressJsonParser $parser)
    {
    }

    public static function format(): string
    {
        return SourceFormat::WP_JSON;
    }

    public function parse(string $body, Feed $feed): ParsedFeedModel
    {
        return $this->parser->parse($body);
    }
}
