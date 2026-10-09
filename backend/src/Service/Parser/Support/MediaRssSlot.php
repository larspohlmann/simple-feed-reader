<?php

declare(strict_types=1);

namespace App\Service\Parser\Support;

final class MediaRssSlot
{
    /** @phpstan-assert-if-true =\DOMElement $node */
    public static function isContentOrThumbnail(\DOMNode $node): bool
    {
        return XmlHelper::isElement($node, 'content', XmlHelper::MEDIA_RSS_NAMESPACE)
            || XmlHelper::isElement($node, 'thumbnail', XmlHelper::MEDIA_RSS_NAMESPACE);
    }

    private function __construct()
    {
    }
}
