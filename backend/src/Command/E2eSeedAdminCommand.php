<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Enum\UserStatus;
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
 * Seeds (or promotes) a single active admin so the e2e suite has an account that
 * can drive the approval queue. There is no admin-creation endpoint, and the
 * e2e suite is black-box, so this is the one out-of-band fixture it needs.
 *
 * Refuses to run under APP_ENV=prod: this mints an active admin from a
 * CLI-supplied password, which must never be reachable on a production host.
 * Idempotent — a second run promotes and re-hashes rather than duplicating.
 */
#[AsCommand(
    name: 'app:e2e:seed-admin',
    description: 'Create or promote an active admin for the e2e suite (non-prod only).',
)]
final class E2eSeedAdminCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
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
        $user->setStatus(UserStatus::Active);
        $user->setApprovedAt($now);
        $user->setPasswordHash($this->hasher->hashPassword($user, $password), $now);

        $this->em->persist($user);
        $this->em->flush();

        $io->success(\sprintf('Active admin ready: %s', $email));

        return Command::SUCCESS;
    }
}
