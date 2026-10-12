<?php

declare(strict_types=1);

namespace App\Service\Backup\Support;

use App\Enum\SourceFormat;
use App\Service\Backup\Exception\InvalidBackupException;
use App\Service\Social\Support\SocialFeedUrl;

/**
 * A backup never carries a private social feed: referenced by URL, it would hand over another user's timeline, and
 * created anew it could never be read.
 */
final class PrivateFeedRefusal
{
    private function __construct()
    {
    }

    /**
     * @throws InvalidBackupException
     */
    public static function ofUrl(string $feedUrl): void
    {
        if (SocialFeedUrl::isPrivate($feedUrl)) {
            throw self::refusal($feedUrl);
        }
    }

    /**
     * @throws InvalidBackupException
     */
    public static function ofFeed(string $feedUrl, string $sourceFormat): void
    {
        self::ofUrl($feedUrl);
        if (SourceFormat::isSocial($sourceFormat)) {
            throw self::refusal($feedUrl);
        }
    }

    private static function refusal(string $feedUrl): InvalidBackupException
    {
        return new InvalidBackupException(
            sprintf('Feed "%s" is a private social feed, which a backup never carries.', $feedUrl),
        );
    }
}
