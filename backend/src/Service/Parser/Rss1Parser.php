<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Parser\Exception\FeedParseException;

final class Rss1Parser
{
    // Temporary stub; replaced by a real RSS 1.0 implementation in Task 6.
    public function parse(\DOMDocument $document): ParsedFeed
    {
        throw new FeedParseException('RSS 1.0 parsing not implemented yet');
    }
}
