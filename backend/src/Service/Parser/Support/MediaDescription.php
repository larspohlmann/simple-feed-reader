<?php

declare(strict_types=1);

namespace App\Service\Parser\Support;

use App\Service\Parser\Model\FeedNamespace;
use App\Service\Parser\Pass\CoreElement;
use App\Service\Text\Support\LinkedPlainText;
use App\Service\Text\Support\PlainText;

/** A Media RSS description: an item's as its body (its own, else its group's), a media element's as its alt text. */
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

    public static function plainText(?\DOMElement $description): ?string
    {
        if ($description === null) {
            return null;
        }

        $text = trim($description->textContent);
        if ($text === '') {
            return null;
        }

        return $description->getAttribute('type') === 'html' ? PlainText::fromHtmlBlocks($text) : $text;
    }

    private static function groupDescription(\DOMElement $item): ?\DOMElement
    {
        $group = CoreElement::extension($item, FeedNamespace::MediaRss)->child('group');

        return $group === null ? null : self::descriptionOf($group);
    }

    public static function descriptionOf(\DOMElement $parent): ?\DOMElement
    {
        return CoreElement::extension($parent, FeedNamespace::MediaRss)->child('description');
    }

    private function __construct()
    {
    }
}
