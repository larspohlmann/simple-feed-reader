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
use App\Service\Operations\UrlCleanup\Pass\LinkRehash;
use App\Service\Operations\UrlCleanup\StoredUrlWriter\DryRunStoredUrlWriter;
use App\Service\Operations\UrlCleanup\StoredUrlWriter\StoredUrlWriter;
use App\Service\Operations\UrlCleanup\StoredUrlWriter\StoredUrlWriterInterface;
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
        private StoredUrlWriter $writer,
        private DryRunStoredUrlWriter $dryRunWriter,
        private int $batchSize = 500,
    ) {
    }

    public function count(): TrackingUrlCleanupReportModel
    {
        return $this->walk($this->dryRunWriter);
    }

    public function clean(): TrackingUrlCleanupReportModel
    {
        return $this->walk($this->writer);
    }

    private function walk(StoredUrlWriterInterface $writer): TrackingUrlCleanupReportModel
    {
        return $this->walkEntries($writer)->plus($this->walkCards($writer));
    }

    private function walkEntries(StoredUrlWriterInterface $writer): TrackingUrlCleanupReportModel
    {
        $report = new TrackingUrlCleanupReportModel();
        $afterId = 0;
        do {
            $batch = $this->entries->entriesAfterId($afterId, $this->batchSize);
            $afterId = array_last($batch)?->requireId() ?? $afterId;
            $report = $report->plus($this->inOwnTransaction(
                fn (): TrackingUrlCleanupReportModel => $this->entryBatch($batch, $writer),
            ));
        } while (\count($batch) === $this->batchSize);

        return $report;
    }

    /** @param list<Entry> $batch */
    private function entryBatch(array $batch, StoredUrlWriterInterface $writer): TrackingUrlCleanupReportModel
    {
        $report = new TrackingUrlCleanupReportModel();
        foreach ($batch as $entry) {
            $report = $report->plus($this->entryChange($entry, $writer));
        }

        return $report;
    }

    private function entryChange(Entry $entry, StoredUrlWriterInterface $writer): TrackingUrlCleanupReportModel
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
        $writer->relocateEntry($entry, $url, $hash);

        return new TrackingUrlCleanupReportModel(
            entriesCleaned: (int) ($url !== $storedUrl),
            hashesChanged: (int) ($hash !== $storedHash),
        );
    }

    private function walkCards(StoredUrlWriterInterface $writer): TrackingUrlCleanupReportModel
    {
        $report = new TrackingUrlCleanupReportModel();
        $afterId = 0;
        do {
            $batch = $this->sharedLinks->addressesAfterId($afterId, $this->batchSize);
            $afterId = array_last($batch)->id ?? $afterId;
            $report = $report->plus($this->inOwnTransaction(
                fn (): TrackingUrlCleanupReportModel => $this->cardBatch($batch, $writer),
            ));
        } while (\count($batch) === $this->batchSize);

        return $report;
    }

    /** @param list<StoredLinkAddressModel> $batch */
    private function cardBatch(array $batch, StoredUrlWriterInterface $writer): TrackingUrlCleanupReportModel
    {
        $moves = $this->movesIn($batch);
        $rehash = new LinkRehash(
            $writer,
            $this->sharedLinks->idsByUrlHashes(array_map(
                static fn (array $move): string => $move['cleaned']->urlHash,
                $moves,
            )),
        );
        $report = new TrackingUrlCleanupReportModel();
        foreach ($moves as $move) {
            $report = $report->plus($rehash->apply($move['stored'], $move['cleaned']));
        }

        return $report;
    }

    /**
     * @param list<StoredLinkAddressModel> $batch
     *
     * @return list<array{stored: StoredLinkAddressModel, cleaned: StoredLinkAddressModel}> the links that move
     */
    private function movesIn(array $batch): array
    {
        $moves = [];
        foreach ($batch as $stored) {
            $url = TrackingFreeUrl::of($stored->url);
            $urlHash = $this->urlNormalizer->hash($url);
            if ($urlHash !== null && ($url !== $stored->url || $urlHash !== $stored->urlHash)) {
                $moves[] = ['stored' => $stored, 'cleaned' => new StoredLinkAddressModel($stored->id, $url, $urlHash)];
            }
        }

        return $moves;
    }

    /** @param \Closure(): TrackingUrlCleanupReportModel $batch */
    private function inOwnTransaction(\Closure $batch): TrackingUrlCleanupReportModel
    {
        $report = $this->entityManager->wrapInTransaction($batch);
        $this->entityManager->clear();

        return $report;
    }
}
