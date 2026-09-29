<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Catalog\CatalogUrlChecker;
use App\Service\Catalog\Model\BrokenCatalogUrlModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reports the URLs in the shipped resources/catalog/catalog.opml that no longer serve a feed; it reads the document,
 * not the database. A scheduled check, never a PR gate: 111 publisher domains always have some outage or bot block.
 */
#[AsCommand(
    name: 'app:catalog:check-urls',
    description: 'Verify every catalog URL still serves a feed',
)]
final class CheckCatalogUrlsCommand extends Command
{
    public function __construct(private readonly CatalogUrlChecker $checker)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Check at most this many URLs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = $this->checker->check(ConsoleOption::limit($input));

        if ($report->isHealthy()) {
            $io->success(\sprintf('All %d catalog URLs still serve a feed.', $report->checked));

            return Command::SUCCESS;
        }

        $io->error(\sprintf('%d of %d catalog URLs need attention:', \count($report->broken), $report->checked));
        $io->listing(array_map(
            static fn (BrokenCatalogUrlModel $broken): string
                => \sprintf('%s (%s): %s', $broken->title, $broken->url, $broken->reason),
            $report->broken,
        ));

        return Command::FAILURE;
    }
}
