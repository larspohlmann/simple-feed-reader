<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use Dom\Element;

/**
 * The still a player keeps beside itself: the first picture inside the element
 * holding the media URL or a near ancestor — a broadcast page has no og:image,
 * but its player wrapper draws the poster next to the player (#796).
 */
final readonly class PlayerPoster
{
    private const int ANCESTOR_LEVELS = 3;

    public static function near(Element $holder): ?string
    {
        $scope = $holder;
        for ($level = 0; $level <= self::ANCESTOR_LEVELS; $level++) {
            if (!$scope instanceof Element || $scope->localName === 'body') {
                return null;
            }
            $poster = self::firstImageIn($scope);
            if ($poster !== null) {
                return $poster;
            }
            $scope = $scope->parentNode;
        }

        return null;
    }

    private static function firstImageIn(Element $scope): ?string
    {
        foreach ($scope->querySelectorAll('img[src]') as $image) {
            $source = $image->getAttribute('src') ?? '';
            if (preg_match('#^https://#i', $source) === 1) {
                return $source;
            }
        }

        return null;
    }
}
