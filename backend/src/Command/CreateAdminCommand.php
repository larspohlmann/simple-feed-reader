<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\Auth\BootstrapAdminProvisioner;
use App\Service\Auth\Support\PasswordPolicy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates the first administrator; the prod-safe bootstrap (app:e2e:seed-admin is not). Refuses while an admin exists
 * unless --force. The password comes from a hidden prompt, never an argument: shell history and ps would show it.
 */
#[AsCommand(
    name: 'app:admin:create',
    description: 'Create the first administrator (prod-safe; refuses if one exists unless --force).',
)]
final class CreateAdminCommand extends Command
{
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

        $answer = $io->askHidden(
            \sprintf('Administrator password (min %d characters)', PasswordPolicy::MINIMUM_LENGTH),
        );
        $password = \is_string($answer) ? $answer : '';
        if (!PasswordPolicy::isLongEnough($password)) {
            $io->error(\sprintf('The password must be at least %d characters.', PasswordPolicy::MINIMUM_LENGTH));

            return Command::INVALID;
        }

        /** @var string $email */
        $email = $input->getArgument('email');
        $admin = $this->provisioner->provision($email, $password);

        $io->success(\sprintf('Administrator ready: %s', $admin->getEmail()));

        return Command::SUCCESS;
    }
}
