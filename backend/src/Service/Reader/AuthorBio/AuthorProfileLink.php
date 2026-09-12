<?php

declare(strict_types=1);

namespace App\Service\Reader\AuthorBio;

use Dom\Element;

/**
 * Recognizes a link to an author-profile page — "View Bio", a linked byline —
 * by a path segment shared across publishers, not by host or link text. It is
 * the signal that marks the trailing furniture of an article as its author bio.
 */
final class AuthorProfileLink
{
    private const array PROFILE_SEGMENTS = [
        'author', 'authors', 'autor', 'autoren',
        'contributor', 'contributors', 'columnist', 'columnists',
        'kolumnist', 'kolumnisten', 'profile', 'staff', 'redaktion', 'journalist',
    ];

    public static function isPresentIn(Element $element): bool
    {
        foreach ($element->getElementsByTagName('a') as $link) {
            if (self::pointsToProfile($link->getAttribute('href') ?? '')) {
                return true;
            }
        }

        return false;
    }

    private static function pointsToProfile(string $href): bool
    {
        $path = parse_url($href, PHP_URL_PATH);
        if (!is_string($path)) {
            return false;
        }

        $segments = explode('/', strtolower($path));

        return array_intersect($segments, self::PROFILE_SEGMENTS) !== [];
    }
}
