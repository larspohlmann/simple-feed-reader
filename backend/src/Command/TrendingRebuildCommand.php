<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\TrendingWindow;
use App\Exception\ValidationException;
use App\Service\Curation\Trending\Exception\TrendingBuildBusyException;
use App\Service\Curation\Trending\SnapshotBuilder\SnapshotBuilderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:trending:rebuild',
    description: 'Build one window of one user now, due or not, and print how its stories matched the stored ones.',
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
        $this->addOption('user', null, InputOption::VALUE_REQUIRED, 'The id of the user to build for');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $userId = ConsoleOption::wholeNumber($input, 'user');
        if ($userId === null) {
            $io->error('The --user option is required: the id of the user to build Trending for.');

            return Command::INVALID;
        }
        $argument = $input->getArgument('window');
        try {
            $window = TrendingWindow::fromRequestValue(\is_string($argument) ? $argument : null);
        } catch (ValidationException $exception) {
            $io->error($exception->errors['window'] ?? [$exception->getMessage()]);

            return Command::INVALID;
        }
        try {
            $report = $this->builder->build($userId, $window)->toLogContext();
        } catch (TrendingBuildBusyException) {
            $io->warning('Skipped: another Trending build is running.');

            return Command::SUCCESS;
        }
        $io->table(
            ['figure', 'value'],
            array_map(null, array_keys($report), array_values($report)),
        );

        return Command::SUCCESS;
    }
}
