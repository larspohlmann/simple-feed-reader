<?php

declare(strict_types=1);

namespace App\Service\Chapter;

use App\Entity\Entry;
use App\Service\Chapter\ChapterFileSource\ChapterFileSourceInterface;
use App\Service\Chapter\Exception\ChapterFileUnreachableException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/** Fetches the chapters file an episode only linked, the first time it is opened, and keeps what it holds. */
final readonly class ChapterFiller
{
    public function __construct(
        private ChapterFileSourceInterface $files,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function fillOnOpen(Entry $entry): void
    {
        $url = $entry->getChaptersUrl();
        if ($url === null) {
            return;
        }

        try {
            $chapters = $this->files->chaptersAt($url);
        } catch (ChapterFileUnreachableException $exception) {
            $this->logger->info(
                'Chapters file unreachable; next open retries.',
                ['url' => $url, 'exception' => $exception],
            );

            return;
        }

        $entry->fillChapters($chapters);
        $this->entityManager->flush();
    }
}
