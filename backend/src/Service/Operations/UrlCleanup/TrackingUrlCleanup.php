<?php

declare(strict_types=1);

namespace App\Service\Operations\UrlCleanup;

use App\Entity\Entry;
use App\Repository\EntryRepository;
use App\Repository\SharedLinkRepository;
use App\Service\Foundation\Url\EntryUrlHash;
use App\Service\Foundation\Url\Support\TrackingFreeUrl;
use App\Service\Foundation\Url\UrlNormalizer;
use App\Service\Operations\UrlCleanup\Model\StoredLinkAddressModel;
use App\Service\Operations\UrlCleanup\Model\TrackingUrlCleanupReportModel;
use Doctrine\ORM\EntityManagerInterface;

/** Brings stored entry and card URLs in line with what ingest now stores, and their url_hash with the cleaned URL. */
final readonly class TrackingUrlCleanup
{
    public function __construct(
        private EntryRepository $entries,
        private SharedLinkRepository $sharedLinks,
        private EntryUrlHash $urlHash,
        private UrlNormalizer $urlNormalizer,
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
            $this->sharedLinks->relocate(...),
        );
    }

    private function walk(\Closure $relocateEntry, \Closure $relocateCard): TrackingUrlCleanupReportModel
    {
        return $this->walkEntries($relocateEntry)->plus($this->walkCards($relocateCard));
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

    private function walkCards(\Closure $relocateCard): TrackingUrlCleanupReportModel
    {
        $report = new TrackingUrlCleanupReportModel();
        $afterId = 0;
        do {
            $batch = $this->sharedLinks->urlsAfterId($afterId, $this->batchSize);
            $report = $report->plus($this->inOwnTransaction(
                fn (): TrackingUrlCleanupReportModel => $this->cardBatch($batch, $relocateCard),
            ));
            $afterId = array_key_last($batch) ?? $afterId;
        } while (\count($batch) === $this->batchSize);

        return $report;
    }

    /** @param array<int, StoredLinkAddressModel> $batch by shared link id */
    private function cardBatch(array $batch, \Closure $relocateCard): TrackingUrlCleanupReportModel
    {
        $report = new TrackingUrlCleanupReportModel();
        foreach ($batch as $linkId => $stored) {
            $report = $report->plus($this->cardChange($linkId, $stored, $relocateCard));
        }

        return $report;
    }

    private function cardChange(
        int $linkId,
        StoredLinkAddressModel $stored,
        \Closure $relocateCard,
    ): TrackingUrlCleanupReportModel {
        $url = TrackingFreeUrl::of($stored->url);
        $urlHash = $this->urlNormalizer->hash($url);
        if ($urlHash === null || ($url === $stored->url && $urlHash === $stored->urlHash)) {
            return new TrackingUrlCleanupReportModel();
        }
        $relocateCard($linkId, $url, $urlHash);

        return new TrackingUrlCleanupReportModel(
            cardsCleaned: (int) ($url !== $stored->url),
            cardHashesChanged: (int) ($urlHash !== $stored->urlHash),
        );
    }

    /** @param \Closure(): TrackingUrlCleanupReportModel $batch */
    private function inOwnTransaction(\Closure $batch): TrackingUrlCleanupReportModel
    {
        $report = $this->entityManager->wrapInTransaction($batch);
        $this->entityManager->clear();

        return $report;
    }
}
