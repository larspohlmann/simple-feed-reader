<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Entry;
use App\Entity\EntryTranscript;
use App\Entity\Feed;
use App\Entity\TranscriptParagraph;
use App\Enum\TranscriptFormat;
use Doctrine\ORM\EntityManagerInterface;

final readonly class TranscriptFixtures
{
    public const string READABLE = "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHallo.\n";

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return list<EntryTranscript> pending rows for episodes 1..$count, later episodes having higher ids */
    public function pendingTranscripts(int $count): array
    {
        $feed = new Feed('https://pod.test/feed.xml');
        $this->entityManager->persist($feed);
        $rows = [];
        for ($index = 1; $index <= $count; $index++) {
            $entry = new Entry(
                $feed,
                'guid-' . $index,
                'https://pod.test/ep' . $index,
                'Episode ' . $index,
                new \DateTimeImmutable('2026-10-27 06:00:00'),
                new \DateTimeImmutable('2026-10-27 05:00:00'),
            );
            $this->entityManager->persist($entry);
            $row = new EntryTranscript($entry, 'https://pod.test/ep' . $index . '.vtt', TranscriptFormat::Vtt, null);
            $this->entityManager->persist($row);
            $rows[] = $row;
        }
        $this->entityManager->flush();

        return $rows;
    }

    public function entryWithFilledTranscript(string $text): Entry
    {
        [$row] = $this->pendingTranscripts(1);
        $row->fill([new TranscriptParagraph(0, null, $text)], $text);
        $this->entityManager->flush();

        return $row->getEntry();
    }
}
