<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Refresh\FeedBodyParser;

use App\Enum\SourceFormat;
use App\Service\Ingestion\Parser\FeedParser;
use App\Service\Ingestion\Parser\Model\ParsedFeedModel;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The default every feed row refreshes through: RSS/Atom documents, read by the FeedParser cascade that discovery
 * and preview share.
 */
#[AsTaggedItem(index: SourceFormat::XML)]
final readonly class XmlBodyParser implements FeedBodyParserInterface
{
    public function __construct(private FeedParser $parser)
    {
    }

    public function parse(string $body, string $feedUrl): ParsedFeedModel
    {
        return $this->parser->parse($body);
    }
}
