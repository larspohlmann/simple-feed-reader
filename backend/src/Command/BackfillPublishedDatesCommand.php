<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\FeedRepository;
use App\Service\EntryIngestor;
use App\Service\Fetch\FeedFetcherInterface;
use App\Service\Refresh\FeedBodyParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-off repair for entries ingested before feed dates were normalised to UTC
 * (#48): re-fetch every feed, re-parse (now UTC-correct), and rewrite the stored
 * publishedAt of matching entries. Only entries still present in their feed can
 * be corrected; older ones age out via pruning.
 */
#[AsCommand(
    name: 'app:feeds:backfill-dates',
    description: 'Re-fetch feeds and correct entries whose publishedAt was stored ahead of UTC',
)]
final class BackfillPublishedDatesCommand extends Command
{
    public function __construct(
        private readonly FeedRepository $feeds,
        private readonly FeedFetcherInterface $fetcher,
        private readonly FeedBodyParser $bodyParser,
        private readonly EntryIngestor $ingestor,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $feeds = $this->feeds->findAll();
        $io->progressStart(\count($feeds));

        $totalUpdated = 0;
        $failures = 0;
        foreach ($feeds as $feed) {
            try {
                // Pass no etag/last-modified so the fetch is unconditional (never 304).
                $response = $this->fetcher->fetch($feed->getUrl());
                $body = $response->body;
                if ($body !== null) {
                    $parsed = $this->bodyParser->parse($feed, $body);
                    $updated = $this->ingestor->correctPublishedDates($feed, $parsed);
                    if ($updated > 0) {
                        $this->em->flush();
                        $totalUpdated += $updated;
                    }
                }
            } catch (\Throwable $e) {
                ++$failures;
                $io->warning(sprintf('%s: %s', $feed->getUrl(), $e->getMessage()));
            }
            $io->progressAdvance();
        }
        $io->progressFinish();

        $io->success(sprintf(
            'Corrected %d entr%s across %d feed(s); %d feed(s) failed to fetch.',
            $totalUpdated,
            $totalUpdated === 1 ? 'y' : 'ies',
            \count($feeds),
            $failures,
        ));

        return Command::SUCCESS;
    }
}
