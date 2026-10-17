<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\Feed;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Service\SavedPosts\Support\SavedFeedUrl;
use App\Service\Social\Model\SocialFeedAddressModel;

/** Which network a feed belongs to: by its private format and address, else by its URL as a profile feed. */
final class SocialFeedClassifier
{
    public static function networkOf(Feed $feed): ?SocialNetwork
    {
        return self::networkFor($feed->getUrl(), $feed->getSourceFormat());
    }

    public static function networkFor(string $url, string $sourceFormat): ?SocialNetwork
    {
        if (SourceFormat::SAVED === $sourceFormat) {
            return SavedFeedUrl::networkOf($url);
        }

        return self::privateAddressFor($url, $sourceFormat)->network ?? ProfileFeedUrl::networkOf($url);
    }

    public static function privateAddressOf(Feed $feed): ?SocialFeedAddressModel
    {
        return self::privateAddressFor($feed->getUrl(), $feed->getSourceFormat());
    }

    private static function privateAddressFor(string $url, string $sourceFormat): ?SocialFeedAddressModel
    {
        return SourceFormat::isSocial($sourceFormat) ? SocialFeedUrl::addressOf($url) : null;
    }

    private function __construct()
    {
    }
}
