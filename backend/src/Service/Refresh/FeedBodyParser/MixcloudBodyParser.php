<?php

declare(strict_types=1);

namespace App\Service\Refresh\FeedBodyParser;

use App\Enum\SourceFormat;
use App\Service\Parser\MixcloudJsonParser;
use App\Service\Parser\Model\ParsedFeedModel;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** Refresh strategy for a Mixcloud user's cloudcasts list, through the parser the subscribe preview uses too. */
#[AsTaggedItem(index: SourceFormat::MIXCLOUD_API)]
final readonly class MixcloudBodyParser implements FeedBodyParserInterface
{
    public function __construct(private MixcloudJsonParser $parser)
    {
    }

    public function parse(string $body, string $feedUrl): ParsedFeedModel
    {
        return $this->parser->parse($body);
    }
}
