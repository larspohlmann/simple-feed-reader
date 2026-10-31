<?php

declare(strict_types=1);

namespace App\Service\Refresh\FeedBodyParser;

use App\Entity\Feed;
use App\Enum\SourceFormat;
use App\Service\Parser\MixcloudJsonParser;
use App\Service\Parser\Model\ParsedFeedModel;

/** Refresh strategy for a Mixcloud user's cloudcasts list, through the parser the subscribe preview uses too. */
final readonly class MixcloudBodyParser implements FeedBodyParserInterface
{
    public function __construct(private MixcloudJsonParser $parser)
    {
    }

    public static function format(): string
    {
        return SourceFormat::MIXCLOUD_API;
    }

    public function parse(string $body, Feed $feed): ParsedFeedModel
    {
        return $this->parser->parse($body);
    }
}
