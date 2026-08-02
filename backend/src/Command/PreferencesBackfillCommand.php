<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Preferences;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates the missing `user_preferences` row for every account that does not
 * have one.
 *
 * This heals a deploy-ordering window, not just an install-time gap. Per
 * activate-release.sh, migrations run against the new schema while `current`
 * still serves the old release. A user who registers in that window is built
 * by the OLD `User::__construct`, which never knew about Preferences: their
 * row is never written, the migration's own backfill has already run and will
 * not run again, and `User::getPreferences()` throws for that account forever.
 *
 * Idempotent: run against a database where every user already has a row, it
 * creates nothing. Meant to run AFTER the `current` flip, so it also catches
 * anyone created in the window between the migration and the flip.
 */
#[AsCommand(
    name: 'app:preferences:backfill',
    description: 'Create a preferences row for every user missing one',
)]
final class PreferencesBackfillCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $usersWithoutPreferences = $this->users->findAllWithoutPreferences();
        foreach ($usersWithoutPreferences as $user) {
            $this->em->persist(new Preferences($user));
        }
        $this->em->flush();

        $io->success(\sprintf('Created %d missing preferences row(s).', \count($usersWithoutPreferences)));

        return Command::SUCCESS;
    }
}
