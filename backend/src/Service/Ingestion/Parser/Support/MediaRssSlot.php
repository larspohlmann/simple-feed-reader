<?php

declare(strict_types=1);

namespace App\Service\Parser\Support;

use App\Service\Parser\Model\FeedNamespace;

final class MediaRssSlot
{
    /** @phpstan-assert-if-true =\DOMElement $node */
    public static function isContentOrThumbnail(\DOMNode $node): bool
    {
        return XmlHelper::isElement($node, 'content', FeedNamespace::MediaRss->value)
            || XmlHelper::isElement($node, 'thumbnail', FeedNamespace::MediaRss->value);
    }

    private function __construct()
    {
    }
}
