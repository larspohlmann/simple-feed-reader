<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\Feed;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Service\Social\Model\SocialFeedAddressModel;

/**
 * Which network a feed belongs to: a private feed by its format and synthetic URL, any other feed when its URL is a
 * network's profile feed. The subscription JSON and the network scopes ask this one rule.
 */
final class SocialFeedClassifier
{
    public static function networkOf(Feed $feed): ?SocialNetwork
    {
        return self::privateAddressOf($feed)->network ?? ProfileFeedUrl::networkOf($feed->getUrl());
    }

    public static function privateAddressOf(Feed $feed): ?SocialFeedAddressModel
    {
        return SourceFormat::isSocial($feed->getSourceFormat()) ? SocialFeedUrl::addressOf($feed->getUrl()) : null;
    }

    private function __construct()
    {
    }
}
