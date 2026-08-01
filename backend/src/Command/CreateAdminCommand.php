<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\Auth\BootstrapAdminProvisioner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates the first administrator on a fresh install. Unlike app:e2e:seed-admin
 * this is prod-safe: it is the supported bootstrap for a shell/exec operator.
 *
 * Refuses when an administrator already exists, so a re-run cannot silently mint
 * a second bootstrap admin; --force overrides for recovery. The password is read
 * from a hidden prompt, never a CLI argument — an argument leaks into shell
 * history and the process list.
 */
#[AsCommand(
    name: 'app:admin:create',
    description: 'Create the first administrator (prod-safe; refuses if one exists unless --force).',
)]
final class CreateAdminCommand extends Command
{
    private const int MINIMUM_PASSWORD_LENGTH = 12;

    public function __construct(
        private readonly UserRepository $users,
        private readonly BootstrapAdminProvisioner $provisioner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Administrator email')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Create even if an administrator already exists');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (true !== $input->getOption('force') && $this->users->hasAnyAdmin()) {
            $io->error('An administrator already exists. Re-run with --force to create another.');

            return Command::FAILURE;
        }

        $answer = $io->askHidden('Administrator password (min 12 characters)');
        $password = \is_string($answer) ? $answer : '';
        if (mb_strlen($password) < self::MINIMUM_PASSWORD_LENGTH) {
            $io->error('The password must be at least 12 characters.');

            return Command::INVALID;
        }

        /** @var string $email */
        $email = $input->getArgument('email');
        $admin = $this->provisioner->provision($email, $password);

        $io->success(\sprintf('Administrator ready: %s', $admin->getEmail()));

        return Command::SUCCESS;
    }
}
