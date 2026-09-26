<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Catalog\BrokenCatalogUrl;
use App\Service\Catalog\CatalogUrlChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fetches every URL in resources/catalog/catalog.opml and reports the ones that
 * no longer serve a feed. Reads the SHIPPED DOCUMENT, not the database: this
 * checks what we hand a new install, which is the thing that rots unnoticed.
 *
 * Run on a schedule, never as a PR gate — 111 publisher domains produce enough
 * rate limits, bot blocks and transient outages to make a merge check useless.
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
        $report = $this->checker->check($this->limit($input));

        if ($report->isHealthy()) {
            $io->success(\sprintf('All %d catalog URLs still serve a feed.', $report->checked));

            return Command::SUCCESS;
        }

        $io->error(\sprintf('%d of %d catalog URLs need attention:', \count($report->broken), $report->checked));
        $io->listing(array_map(
            static fn (BrokenCatalogUrl $broken): string
                => \sprintf('%s (%s): %s', $broken->title, $broken->url, $broken->reason),
            $report->broken,
        ));

        return Command::FAILURE;
    }

    private function limit(InputInterface $input): ?int
    {
        $value = $input->getOption('limit');
        if (!\is_string($value) || !ctype_digit($value)) {
            return null;
        }

        return max(1, (int) $value);
    }
}
