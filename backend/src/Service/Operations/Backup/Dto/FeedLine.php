<?php

declare(strict_types=1);

namespace App\Service\Operations\Backup\Dto;

use App\Service\Operations\Backup\Exception\InvalidBackupException;
use App\Service\Operations\Backup\Support\BackupLineFormat;
use App\Service\Operations\Backup\Support\BackupSchema;
use App\Service\Operations\Backup\Support\LineField;
use App\Service\Operations\Backup\Support\PrivateFeedRefusal;
use App\Service\Operations\Backup\Support\SavedFeedRefusal;
use App\Service\Social\Social\Model\ExportedSubscriptionModel;

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
     * @throws InvalidBackupException for a private social feed or a mixed saved feed
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
        PrivateFeedRefusal::ofFeed($feedLine->url, $feedLine->sourceFormat);
        SavedFeedRefusal::ofFeed($feedLine->url, $feedLine->sourceFormat);

        return $feedLine;
    }

    public static function of(ExportedSubscriptionModel $exported): self
    {
        $feed = $exported->subscription->getFeed();

        return new self(
            url: $exported->feedUrl,
            siteUrl: $feed->getSiteUrl(),
            title: $feed->getTitle(),
            description: $feed->getDescription(),
            faviconUrl: $feed->getFaviconUrl(),
            imageUrl: $feed->getImageUrl(),
            sourceFormat: $exported->feedSourceFormat,
        );
    }

    public function toBackupLine(): string
    {
        return BackupLineFormat::encode([
            'kind' => BackupSchema::KIND_FEED,
            'url' => $this->url,
            'siteUrl' => $this->siteUrl,
            'title' => $this->title,
            'description' => $this->description,
            'faviconUrl' => $this->faviconUrl,
            'imageUrl' => $this->imageUrl,
            'sourceFormat' => $this->sourceFormat,
        ]);
    }
}
