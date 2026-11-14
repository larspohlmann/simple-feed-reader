<?php

declare(strict_types=1);

namespace App\Service\Backup\Model;

use App\Service\Backup\Support\BackupSchema;

/**
 * The foundation's record lines, encoded before the entry-part walk so they name the feeds the parts were built from,
 * even if the account changes mid-export. Plain strings: the walk clear()s the entity manager.
 */
final readonly class FoundationSnapshotModel
{
    /**
     * @param list<string>          $tagLines
     * @param list<string>          $savedSearchLines
     * @param list<string>          $feedLines
     * @param list<string>          $subscriptionLines
     * @param array<int, string>    $feedUrlsByFeedId
     */
    public function __construct(
        private string $accountLine,
        private array $tagLines,
        private array $savedSearchLines,
        private array $feedLines,
        private array $subscriptionLines,
        public array $feedUrlsByFeedId,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return [
            BackupSchema::KIND_TAG => \count($this->tagLines),
            BackupSchema::KIND_SAVED_SEARCH => \count($this->savedSearchLines),
            BackupSchema::KIND_FEED => \count($this->feedLines),
            BackupSchema::KIND_SUBSCRIPTION => \count($this->subscriptionLines),
        ];
    }

    public function part(string $header, string $footer): BackupPartModel
    {
        $lines = [
            $header,
            $this->accountLine,
            ...$this->tagLines,
            ...$this->savedSearchLines,
            ...$this->feedLines,
            ...$this->subscriptionLines,
            $footer,
        ];

        return BackupPartModel::foundation(BackupPartModel::gzip(implode("\n", $lines) . "\n"));
    }
}
