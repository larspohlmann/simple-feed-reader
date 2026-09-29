<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\Auth\PasswordResetter;
use App\Service\Auth\Support\PasswordPolicy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Resets one user's password from a shell. The supported recovery path on a
 * mailless instance (issue #230), where the email reset flow cannot deliver.
 * With --generate the command mints a random password and prints it once, for
 * the operator to relay out of band; otherwise it reads one from a hidden
 * prompt. Never takes the password as an argument — that leaks into shell
 * history and the process list.
 */
#[AsCommand(
    name: 'app:user:reset-password',
    description: 'Reset a user password from the shell (mailless-instance recovery).',
)]
final class ResetUserPasswordCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordResetter $resetter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'The account email')
            ->addOption('generate', null, InputOption::VALUE_NONE, 'Generate a random password and print it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $email */
        $email = $input->getArgument('email');
        $user = $this->users->findOneByEmail($email);
        if (null === $user) {
            $io->error(\sprintf('No account with email %s.', $email));

            return Command::FAILURE;
        }

        if (true === $input->getOption('generate')) {
            $generated = $this->resetter->generateAndSet($user);
            $io->success(\sprintf('Password reset for %s.', $user->getEmail()));
            $io->writeln(\sprintf('New password: %s', $generated));
            $io->note('Relay this to the user over a trusted channel. It is shown only once.');

            return Command::SUCCESS;
        }

        $answer = $io->askHidden(\sprintf('New password (min %d characters)', PasswordPolicy::MINIMUM_LENGTH));
        $password = \is_string($answer) ? $answer : '';
        if (!PasswordPolicy::isLongEnough($password)) {
            $io->error(\sprintf('The password must be at least %d characters.', PasswordPolicy::MINIMUM_LENGTH));

            return Command::INVALID;
        }

        $this->resetter->setPassword($user, $password);
        $io->success(\sprintf('Password reset for %s.', $user->getEmail()));

        return Command::SUCCESS;
    }
}
