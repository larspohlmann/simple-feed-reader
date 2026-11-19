<?php

declare(strict_types=1);

namespace App\Service\Content\LinkCard\Support;

use App\Service\Foundation\Html\Support\HtmlDocumentParser;
use App\Service\Foundation\Html\Support\TextNodes;
use App\Service\Foundation\Text\Support\Whitespace;
use App\Service\Foundation\Url\Support\TrackingFreeUrl;
use Dom\Element;
use Dom\Text;

/** A Mastodon quote: a link whose paragraph reads "RE:" before it. */
final class QuoteLine
{
    private const string MARKER = 'RE:';

    public static function removedFrom(string $html, string $url): string
    {
        $document = HtmlDocumentParser::parseFragment($html);
        $target = TrackingFreeUrl::stripTracking($url);
        foreach ($document->querySelectorAll('a[href]') as $anchor) {
            $paragraph = $anchor->closest('p');
            $href = (string) $anchor->getAttribute('href');
            if ($paragraph === null || TrackingFreeUrl::stripTracking($href) !== $target) {
                continue;
            }
            $marker = self::markerBefore($anchor, $paragraph);
            if ($marker !== null) {
                self::remove($anchor, $paragraph, $marker);

                return $document->body->innerHTML ?? $html;
            }
        }

        return $html;
    }

    /** The quoted link without its tracking parameters, as the share plans compare it. */
    public static function trackingFreeTargetIn(string $html): ?string
    {
        return TrackingFreeUrl::orNull(self::targetIn($html));
    }

    /** The link the body's first "RE:" paragraph quotes; null when it quotes nothing. */
    public static function targetIn(string $html): ?string
    {
        $document = HtmlDocumentParser::parseFragment($html);
        foreach ($document->querySelectorAll('a[href]') as $anchor) {
            $paragraph = $anchor->closest('p');
            if ($paragraph !== null && self::markerBefore($anchor, $paragraph) !== null) {
                return $anchor->getAttribute('href');
            }
        }

        return null;
    }

    /** @return list<Text>|null the paragraph's text before the anchor, when it reads "RE:" */
    private static function markerBefore(Element $anchor, Element $paragraph): ?array
    {
        $before = [];
        foreach (TextNodes::in($paragraph) as $text) {
            if ($anchor->contains($text)) {
                break;
            }
            $before[] = $text;
        }
        $read = implode('', array_map(static fn (Text $text): string => $text->data, $before));

        return Whitespace::collapse($read) === self::MARKER ? $before : null;
    }

    /** @param list<Text> $marker */
    private static function remove(Element $anchor, Element $paragraph, array $marker): void
    {
        foreach ($marker as $text) {
            $text->remove();
        }
        $anchor->remove();
        if ($paragraph->firstElementChild === null && Whitespace::collapse($paragraph->textContent) === '') {
            $paragraph->remove();
        }
    }

    private function __construct()
    {
    }
}
