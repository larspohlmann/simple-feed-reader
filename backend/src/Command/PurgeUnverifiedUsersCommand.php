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
 * Deletes accounts that never confirmed their email. Without this, registering
 * and abandoning reserves an address forever, since the unique constraint on
 * email would block the real owner from ever signing up.
 *
 * Runs over SSH: `php83 -q -f bin/console app:users:purge-unverified`.
 */
#[AsCommand(
    name: 'app:users:purge-unverified',
    description: 'Delete accounts that never confirmed their email address',
)]
final class PurgeUnverifiedUsersCommand extends Command
{
    private const MAX_AGE = 'PT48H';

    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $cutoff = $this->clock->now()->sub(new \DateInterval(self::MAX_AGE));
        $stale = $this->users->findUnverifiedCreatedBefore($cutoff);

        foreach ($stale as $user) {
            // remove(), not a DQL bulk DELETE: going through the ORM keeps the
            // unit of work aware of what left, and the action_token rows follow
            // via the FK's ON DELETE CASCADE. A bulk DELETE would bypass the
            // unit of work entirely.
            $this->em->remove($user);
        }

        $this->em->flush();

        $io->success(sprintf('Purged %d unverified account(s).', \count($stale)));

        return Command::SUCCESS;
    }
}
