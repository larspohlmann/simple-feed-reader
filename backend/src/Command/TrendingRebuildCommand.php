<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\TrendingWindow;
use App\Exception\ValidationException;
use App\Service\Curation\Trending\SnapshotBuilder\SnapshotBuilderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:trending:rebuild',
    description: 'Build one Trending window now, due or not, and print how its stories matched the stored ones.',
)]
final class TrendingRebuildCommand extends Command
{
    public function __construct(private readonly SnapshotBuilderInterface $builder)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('window', InputArgument::REQUIRED, 'day or week');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $argument = $input->getArgument('window');
        try {
            $window = TrendingWindow::fromRequestValue(\is_string($argument) ? $argument : null);
        } catch (ValidationException $exception) {
            $io->error($exception->errors['window'] ?? [$exception->getMessage()]);

            return Command::INVALID;
        }
        $report = $this->builder->build($window)->toLogContext();
        $io->table(
            ['figure', 'value'],
            array_map(null, array_keys($report), array_values($report)),
        );

        return Command::SUCCESS;
    }
}
