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
 * Deletes the throwaway accounts the e2e suites leave behind. The backend suite
 * mints `e2e-…@example.com` and the Playwright onboarding journey registers
 * `onboarding-…@example.com`; both confirm and approve their accounts, so the
 * unverified-account purge never reclaims them and the dev database grows run
 * after run (#184). The e2e runners call this before each run, so cleanup is
 * automatic rather than a thing to remember.
 *
 * Refuses to run outside dev/test: it deletes accounts by an email pattern, and
 * that pattern must never be evaluated against production data.
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
        private readonly EntityManagerInterface $em,
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
            // remove(), not a DQL bulk DELETE: going through the ORM keeps the unit
            // of work aware of what left, and each account's subscriptions, tags and
            // read state follow via FK ON DELETE CASCADE. Same reasoning as
            // PurgeUnverifiedUsersCommand.
            $this->em->remove($user);
        }

        $this->em->flush();

        $io->success(sprintf('Purged %d e2e fixture account(s).', \count($fixtures)));

        return Command::SUCCESS;
    }
}
