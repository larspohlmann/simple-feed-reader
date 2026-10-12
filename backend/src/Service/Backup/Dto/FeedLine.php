<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

use App\Enum\SourceFormat;
use App\Service\Backup\Exception\InvalidBackupException;
use App\Service\Backup\Support\LineField;
use App\Service\Social\Support\SocialFeedUrl;

/**
 * One feed the account (or another account sharing it) subscribes to.
 */
final readonly class FeedLine
{
    public function __construct(
        public string $url,
        public ?string $siteUrl,
        public ?string $title,
        public ?string $description,
        public ?string $faviconUrl,
        public ?string $imageUrl,
        public string $sourceFormat,
    ) {
    }

    /**
     * @param array<string, mixed> $line
     *
     * @throws InvalidBackupException for a private social feed: referenced by URL, it would hand over another
     *                                user's timeline, and created anew it could never be read
     */
    public static function fromLine(array $line): self
    {
        $feedLine = new self(
            url: LineField::string($line, 'url'),
            siteUrl: LineField::stringOrNull($line, 'siteUrl'),
            title: LineField::stringOrNull($line, 'title'),
            description: LineField::stringOrNull($line, 'description'),
            faviconUrl: LineField::stringOrNull($line, 'faviconUrl'),
            imageUrl: LineField::stringOrNull($line, 'imageUrl'),
            sourceFormat: LineField::string($line, 'sourceFormat'),
        );
        if (SocialFeedUrl::isPrivate($feedLine->url) || SourceFormat::isSocial($feedLine->sourceFormat)) {
            throw new InvalidBackupException(
                sprintf('Feed "%s" is a private social feed, which a backup never carries.', $feedLine->url),
            );
        }

        return $feedLine;
    }
}
