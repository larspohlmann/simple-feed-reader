<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Parser\Exception\FeedParseException;

final class AtomParser
{
    // Temporary stub; replaced by a real Atom implementation in Task 6.
    public function parse(\DOMDocument $document): ParsedFeed
    {
        throw new FeedParseException('Atom parsing not implemented yet');
    }
}
