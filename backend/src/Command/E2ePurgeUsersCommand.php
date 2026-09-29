<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Deletes the confirmed e2e fixture accounts (`e2e-…@example.com`, `onboarding-…@example.com`) that the
 * unverified-account purge never reclaims; the e2e runners call it before each run. Refuses outside dev/test.
 */
#[AsCommand(
    name: 'app:e2e:purge-users',
    description: 'Delete the throwaway accounts the e2e suites leave behind (dev/test only).',
)]
final class E2ePurgeUsersCommand extends Command
{
    /**
     * The seeded admin (app:e2e:seed-admin) shares the `e2e-` fixture prefix but
     * the suites log in with it, so it must survive the purge. Kept in step with
     * E2eSeedAdminCommand's default email argument.
     */
    private const string PROTECTED_ADMIN_EMAIL = 'e2e-admin@example.com';

    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%kernel.environment%')]
        private readonly string $appEnv,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('dev' !== $this->appEnv && 'test' !== $this->appEnv) {
            $io->error(sprintf(
                'app:e2e:purge-users only runs in the dev or test environment, not "%s".',
                $this->appEnv,
            ));

            return Command::FAILURE;
        }

        $fixtures = $this->users->findE2eFixtureAccounts(self::PROTECTED_ADMIN_EMAIL);

        foreach ($fixtures as $user) {
            // remove(), not a bulk DQL DELETE, so the unit of work knows what left; subscriptions, tags and read state
            // follow by FK ON DELETE CASCADE.
            $this->entityManager->remove($user);
        }

        $this->entityManager->flush();

        $io->success(sprintf('Purged %d e2e fixture account(s).', \count($fixtures)));

        return Command::SUCCESS;
    }
}
