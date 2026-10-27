<?php

declare(strict_types=1);

namespace App\Service\Ingest;

use App\Entity\Entry;
use App\Entity\EntryTranscript;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Url\Support\AbsoluteHttpUrl;
use Doctrine\ORM\EntityManagerInterface;

/** Queues the transcript a newly created entry declared; TranscriptSweep fetches it. The caller flushes. */
final readonly class EntryTranscriptWriter
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @param list<array{0: Entry, 1: ParsedEntryModel}> $pairs */
    public function attach(array $pairs): void
    {
        foreach ($pairs as [$entry, $parsed]) {
            $this->attachOne($entry, $parsed);
        }
    }

    private function attachOne(Entry $entry, ParsedEntryModel $parsed): void
    {
        $declared = $parsed->media->mediaBundle?->transcript;
        $url = AbsoluteHttpUrl::orNullWithin($declared?->url, EntryTranscript::URL_MAX_LENGTH);
        if ($declared === null || $url === null) {
            return;
        }

        $this->entityManager->persist(new EntryTranscript($entry, $url, $declared->format, $declared->language));
    }
}
