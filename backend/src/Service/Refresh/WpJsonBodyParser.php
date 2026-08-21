<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Feed;
use App\Enum\SourceFormat;
use App\Service\Parser\ParsedFeed;
use App\Service\Parser\WordPressJsonParser;

/**
 * Refresh strategy for feeds subscribed as a WordPress REST posts endpoint.
 * Wraps WordPressJsonParser so refresh and the subscribe-dialog preview read
 * the same JSON through one implementation, exactly as XmlBodyParser wraps
 * FeedParser. Parse failures surface as FeedParseException, so the runner's
 * recordFailure / backoff / Erroring handling applies unchanged.
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

    public function parse(string $body, Feed $feed): ParsedFeed
    {
        return $this->parser->parse($body);
    }
}
