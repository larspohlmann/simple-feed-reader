<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\SavedSearchRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The rebuild path for saved_search_entry (#1116): after a search-engine
 * reindex has settled, or whenever the table is suspect. Rows are never
 * removed; the re-walk only adds what the matcher finds missing.
 */
#[AsCommand(
    name: 'app:saved-search:rematch',
    description: 'Start every saved search over at membership mark 0, so the sweep re-matches the whole history.',
)]
final class SavedSearchRematchCommand extends Command
{
    public function __construct(private readonly SavedSearchRepository $savedSearches)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $reset = $this->savedSearches->resetAllMarks();
        $io->success(\sprintf(
            '%d saved-search membership marks reset; the next sweep re-matches every entry.',
            $reset,
        ));

        return Command::SUCCESS;
    }
}
