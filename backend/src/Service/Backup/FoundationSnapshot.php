<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * The foundation's record lines, encoded before the entry-part walk begins so
 * they name the same subscriptions and feeds the entry parts were built
 * against. The foundation is still written last (its header needs the part
 * count and totals the walk only knows once it ends), but its body is fixed up
 * front: a mid-export unsubscribe or a feed's 301 rewrite can no longer leave
 * the archive referencing a feed the foundation omits. The walk clear()s the
 * entity manager, so these must be plain strings, taken before the first clear.
 */
final readonly class FoundationSnapshot
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

    public function part(string $header, string $footer): BackupPart
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

        return BackupPart::foundation(BackupPart::gzip(implode("\n", $lines) . "\n"));
    }
}
