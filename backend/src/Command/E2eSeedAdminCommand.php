<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Seeds or promotes one active admin for the black-box e2e suite, which has no other way to get one. Idempotent.
 * Refuses under APP_ENV=prod: it mints an admin from a password given on the command line.
 */
#[AsCommand(
    name: 'app:e2e:seed-admin',
    description: 'Create or promote an active admin for the e2e suite (non-prod only).',
)]
final class E2eSeedAdminCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ClockInterface $clock,
        #[Autowire('%kernel.environment%')]
        private readonly string $appEnv,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::OPTIONAL, 'Admin email', 'e2e-admin@example.com')
            ->addArgument('password', InputArgument::OPTIONAL, 'Admin password', 'e2e-admin-password-123');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->appEnv) {
            $io->error('app:e2e:seed-admin is disabled in the prod environment.');

            return Command::FAILURE;
        }

        /** @var string $email */
        $email = $input->getArgument('email');
        /** @var string $password */
        $password = $input->getArgument('password');

        $now = $this->clock->now();
        $user = $this->users->findOneByEmail($email) ?? new User($email, $now);

        $user->setRoles(['ROLE_ADMIN']);
        $user->approve($now);
        $user->setPasswordHash($this->hasher->hashPassword($user, $password), $now);
        // The scrape fallback is opt-in and the reader suite exercises it, so the fixture states the preference
        // rather than inherit the default.
        $user->getPreferences()->setScrapeFallbackEnabled(true);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(\sprintf('Active admin ready: %s', $email));

        return Command::SUCCESS;
    }
}
