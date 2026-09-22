<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SavedSearchRematchCommand;
use App\Entity\SavedSearch;
use App\Entity\User;
use App\Repository\SavedSearchRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\StoredMark;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SavedSearchRematchCommandTest extends DbTestCase
{
    public function testResetsEveryMarkAndReportsHowMany(): void
    {
        $user = new User('rematch@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $search = new SavedSearch($user, 'climate', false);
        $search->advanceMatchedUpTo(500);
        $this->em->persist($user);
        $this->em->persist($search);
        $this->em->flush();
        $repository = self::getContainer()->get(SavedSearchRepository::class);
        self::assertInstanceOf(SavedSearchRepository::class, $repository);

        $tester = new CommandTester(new SavedSearchRematchCommand($repository));
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('1 saved-search membership marks reset', $tester->getDisplay());
        self::assertSame(0, StoredMark::of($this->em, $search));
    }
}
