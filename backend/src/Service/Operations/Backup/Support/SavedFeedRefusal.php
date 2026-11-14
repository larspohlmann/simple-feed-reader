<?php

declare(strict_types=1);

namespace App\Service\Operations\Backup\Support;

use App\Enum\SourceFormat;
use App\Service\Operations\Backup\Exception\InvalidBackupException;
use App\Service\Library\SavedPosts\Support\SavedFeedUrl;

/** A saved feed is a saved: URL with the saved format, both or neither: a file must not make a shared feed a saved one. */
final class SavedFeedRefusal
{
    private function __construct()
    {
    }

    /**
     * @throws InvalidBackupException
     */
    public static function ofFeed(string $feedUrl, string $sourceFormat): void
    {
        if (SavedFeedUrl::isSaved($feedUrl) === SourceFormat::isSaved($sourceFormat)) {
            return;
        }

        throw new InvalidBackupException(
            sprintf('Feed "%s" mixes a saved-feed address and format; a backup never does.', $feedUrl),
        );
    }
}
