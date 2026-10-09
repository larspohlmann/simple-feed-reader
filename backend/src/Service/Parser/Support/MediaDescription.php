<?php

declare(strict_types=1);

namespace App\Service\Parser\Support;

use App\Service\Text\Support\LinkedPlainText;

/** A Media RSS item's description as a body: the item's own, else its group's. */
final class MediaDescription
{
    public static function html(\DOMElement $item): ?string
    {
        $description = self::descriptionOf($item) ?? self::groupDescription($item);
        if ($description === null) {
            return null;
        }

        $text = trim($description->textContent);
        if ($text === '') {
            return null;
        }

        return $description->getAttribute('type') === 'html' ? $text : LinkedPlainText::asHtml($text);
    }

    private static function groupDescription(\DOMElement $item): ?\DOMElement
    {
        $group = XmlHelper::childElement($item, 'group', XmlHelper::MEDIA_RSS_NAMESPACE);

        return $group === null ? null : self::descriptionOf($group);
    }

    private static function descriptionOf(\DOMElement $parent): ?\DOMElement
    {
        return XmlHelper::childElement($parent, 'description', XmlHelper::MEDIA_RSS_NAMESPACE);
    }

    private function __construct()
    {
    }
}
