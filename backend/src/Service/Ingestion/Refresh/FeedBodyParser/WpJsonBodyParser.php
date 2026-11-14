<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Refresh\FeedBodyParser;

use App\Enum\SourceFormat;
use App\Service\Ingestion\Parser\Model\ParsedFeedModel;
use App\Service\Ingestion\Parser\WordPressJsonParser;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Refresh strategy for a WordPress REST posts endpoint, through the WordPressJsonParser the subscribe preview uses
 * too. Its failures are FeedParseException, so the usual failure handling applies.
 */
#[AsTaggedItem(index: SourceFormat::WP_JSON)]
final readonly class WpJsonBodyParser implements FeedBodyParserInterface
{
    public function __construct(private WordPressJsonParser $parser)
    {
    }

    public function parse(string $body, string $feedUrl): ParsedFeedModel
    {
        return $this->parser->parse($body);
    }
}
