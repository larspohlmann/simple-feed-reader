<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\User;
use App\Tests\DbTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PreferencesBackfillCommandTest extends DbTestCase
{
    private function seedUser(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('-1 day'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * Simulates the deploy-ordering window: an account whose row was written
     * by the OLD `User::__construct`, before Preferences existed. Deleting the
     * row the constructor just created, rather than never running the
     * constructor, is the only way to reach that state through this test's
     * own schema.
     */
    private function stripPreferences(User $user): void
    {
        $this->em->getConnection()->executeStatement(
            'DELETE FROM user_preferences WHERE user_id = ?',
            [$user->getId()],
        );
        $this->em->clear();
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());

        return new CommandTester($application->find('app:preferences:backfill'));
    }

    private function preferencesCount(): int
    {
        /** @var int $count */
        $count = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM user_preferences');

        return (int) $count;
    }

    public function testReportsZeroWhenEveryUserAlreadyHasPreferences(): void
    {
        $this->seedUser('has-preferences@example.com');

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertStringContainsString('Created 0 missing preferences row(s).', $tester->getDisplay());
        self::assertSame(1, $this->preferencesCount());
    }

    public function testCreatesTheMissingRowForExactlyTheAffectedUser(): void
    {
        $healthy = $this->seedUser('healthy@example.com');
        $broken = $this->seedUser('broken@example.com');
        $this->stripPreferences($broken);
        self::assertSame(1, $this->preferencesCount(), 'only the healthy user should still have a row');

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertStringContainsString('Created 1 missing preferences row(s).', $tester->getDisplay());
        self::assertSame(2, $this->preferencesCount());

        // Fresh identity map: the command's own flush does not update the
        // in-memory $broken object this test holds, only the database row.
        $this->em->clear();

        /** @var User $reloadedBroken */
        $reloadedBroken = $this->em->getRepository(User::class)->find($broken->getId());
        self::assertFalse(
            $reloadedBroken->getPreferences()->isScrapeFallbackEnabled(),
            'the healed row must carry the same off-by-default value the constructor would have written',
        );

        /** @var User $reloadedHealthy */
        $reloadedHealthy = $this->em->getRepository(User::class)->find($healthy->getId());
        self::assertFalse(
            $reloadedHealthy->getPreferences()->isScrapeFallbackEnabled(),
            'the pre-existing row must survive untouched',
        );
    }

    public function testIsIdempotentOnASecondRun(): void
    {
        $broken = $this->seedUser('broken@example.com');
        $this->stripPreferences($broken);

        $this->tester()->execute([]);
        $secondRun = $this->tester();
        self::assertSame(Command::SUCCESS, $secondRun->execute([]));

        self::assertStringContainsString('Created 0 missing preferences row(s).', $secondRun->getDisplay());
        self::assertSame(1, $this->preferencesCount());
    }
}
