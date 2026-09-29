<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes accounts that never confirmed their email, so an abandoned registration does not reserve the address.
 * Runs over SSH: `php83 -q -f bin/console app:users:purge-unverified`.
 */
#[AsCommand(
    name: 'app:users:purge-unverified',
    description: 'Delete accounts that never confirmed their email address',
)]
final class PurgeUnverifiedUsersCommand extends Command
{
    private const string MAX_AGE = 'PT48H';

    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    /**
     * @throws \DateInvalidOperationException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $cutoff = $this->clock->now()->sub(new \DateInterval(self::MAX_AGE));
        $stale = $this->users->findUnverifiedCreatedBefore($cutoff);

        foreach ($stale as $user) {
            // remove(), not a bulk DQL DELETE, so the unit of work knows what left; the action_token rows follow by
            // FK ON DELETE CASCADE.
            $this->entityManager->remove($user);
        }

        $this->entityManager->flush();

        $io->success(sprintf('Purged %d unverified account(s).', \count($stale)));

        return Command::SUCCESS;
    }
}
