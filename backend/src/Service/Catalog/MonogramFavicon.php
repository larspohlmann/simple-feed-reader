<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\CatalogFeed;

/**
 * The offline stand-in for a favicon: the feed's initial on its category colour,
 * as SVG. Serving this instead of fetching on a cache miss is what keeps the
 * picker free of outbound requests — 111 cards render with no network fan-out,
 * and e2e works with no publisher reachable.
 */
final readonly class MonogramFavicon
{
    public const string CONTENT_TYPE = 'image/svg+xml';

    public function render(CatalogFeed $feed): string
    {
        $letter = $this->escape($this->initialOf($feed->getTitle()));
        $color = $this->escape($feed->getCategory()->getColor());

        return \sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="32" height="32">'
            . '<rect width="32" height="32" rx="7" fill="%s"/>'
            . '<text x="16" y="17" fill="#ffffff" font-family="system-ui,sans-serif" font-size="17" '
            . 'font-weight="700" text-anchor="middle" dominant-baseline="central">%s</text>'
            . '</svg>',
            $color,
            $letter,
        );
    }

    private function initialOf(string $title): string
    {
        $initial = mb_strtoupper(mb_substr(trim($title), 0, 1));

        return '' === $initial ? '?' : $initial;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
