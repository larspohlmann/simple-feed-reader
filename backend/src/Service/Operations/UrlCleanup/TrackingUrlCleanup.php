<?php

declare(strict_types=1);

namespace App\Service\Operations\UrlCleanup;

use App\Entity\Entry;
use App\Repository\EntryRepository;
use App\Repository\SharedLinkRepository;
use App\Service\Foundation\Url\EntryUrlHash;
use App\Service\Foundation\Url\Support\TrackingFreeUrl;
use App\Service\Operations\UrlCleanup\Model\TrackingUrlCleanupReportModel;
use Doctrine\ORM\EntityManagerInterface;

/** Brings stored entry and card URLs in line with what ingest now stores, and their url_hash with the cleaned URL. */
final readonly class TrackingUrlCleanup
{
    public function __construct(
        private EntryRepository $entries,
        private SharedLinkRepository $sharedLinks,
        private EntryUrlHash $urlHash,
        private EntityManagerInterface $entityManager,
        private int $batchSize = 500,
    ) {
    }

    public function count(): TrackingUrlCleanupReportModel
    {
        $writeNothing = static function (): void {
        };

        return $this->walk($writeNothing, $writeNothing);
    }

    public function clean(): TrackingUrlCleanupReportModel
    {
        return $this->walk(
            static fn (Entry $entry, string $url, ?string $urlHash) => $entry->relocate($url, $urlHash),
            $this->sharedLinks->moveTo(...),
        );
    }

    private function walk(\Closure $relocateEntry, \Closure $moveCard): TrackingUrlCleanupReportModel
    {
        return $this->walkEntries($relocateEntry)->plus($this->walkCards($moveCard));
    }

    private function walkEntries(\Closure $relocateEntry): TrackingUrlCleanupReportModel
    {
        $report = new TrackingUrlCleanupReportModel();
        $afterId = 0;
        do {
            $batch = $this->entries->entriesAfterId($afterId, $this->batchSize);
            $afterId = $batch === [] ? $afterId : $batch[array_key_last($batch)]->requireId();
            $report = $report->plus($this->inOwnTransaction(
                fn (): TrackingUrlCleanupReportModel => $this->entryBatch($batch, $relocateEntry),
            ));
        } while (\count($batch) === $this->batchSize);

        return $report;
    }

    /** @param list<Entry> $batch */
    private function entryBatch(array $batch, \Closure $relocateEntry): TrackingUrlCleanupReportModel
    {
        $report = new TrackingUrlCleanupReportModel();
        foreach ($batch as $entry) {
            $report = $report->plus($this->entryChange($entry, $relocateEntry));
        }

        return $report;
    }

    private function entryChange(Entry $entry, \Closure $relocateEntry): TrackingUrlCleanupReportModel
    {
        $storedUrl = $entry->getUrl();
        if ($storedUrl === null) {
            return new TrackingUrlCleanupReportModel();
        }
        $storedHash = $entry->getUrlHash();
        $url = TrackingFreeUrl::of($storedUrl);
        $hash = $this->urlHash->of($entry->getGuid(), $url);
        if ($url === $storedUrl && $hash === $storedHash) {
            return new TrackingUrlCleanupReportModel();
        }
        $relocateEntry($entry, $url, $hash);

        return new TrackingUrlCleanupReportModel((int) ($url !== $storedUrl), (int) ($hash !== $storedHash));
    }

    private function walkCards(\Closure $moveCard): TrackingUrlCleanupReportModel
    {
        $report = new TrackingUrlCleanupReportModel();
        $afterId = 0;
        do {
            $batch = $this->sharedLinks->urlsAfterId($afterId, $this->batchSize);
            $report = $report->plus($this->inOwnTransaction(
                static fn (): TrackingUrlCleanupReportModel => self::cardBatch($batch, $moveCard),
            ));
            $afterId = array_key_last($batch) ?? $afterId;
        } while (\count($batch) === $this->batchSize);

        return $report;
    }

    /** @param array<int, string> $batch shared link URLs by link id */
    private static function cardBatch(array $batch, \Closure $moveCard): TrackingUrlCleanupReportModel
    {
        $cleaned = 0;
        foreach ($batch as $linkId => $url) {
            $trackingFree = TrackingFreeUrl::of($url);
            if ($trackingFree !== $url) {
                $moveCard($linkId, $trackingFree);
                ++$cleaned;
            }
        }

        return new TrackingUrlCleanupReportModel(cardsCleaned: $cleaned);
    }

    /** @param \Closure(): TrackingUrlCleanupReportModel $batch */
    private function inOwnTransaction(\Closure $batch): TrackingUrlCleanupReportModel
    {
        $report = $this->entityManager->wrapInTransaction($batch);
        $this->entityManager->clear();

        return $report;
    }
}
