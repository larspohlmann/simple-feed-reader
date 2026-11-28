<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\YourStreamExplainCommand;
use App\Enum\FeedLanguage;
use App\Repository\SavedSearchRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\RequiresServices;
use App\Tests\Support\YourStreamExplainFixtures;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class YourStreamExplainCommandTest extends DbTestCase
{
    use RequiresServices;

    public function testTheCommandNeedsAUser(): void
    {
        $tester = new CommandTester($this->command(new YourStreamExplainFixtures($this->entityManager)));

        $tester->execute([]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('--user', $tester->getDisplay());
    }

    public function testTheCommandPrintsTheWordsAndTheTable(): void
    {
        $fixtures = new YourStreamExplainFixtures($this->entityManager);
        $user = $fixtures->reader('explain-command@example.com');
        $feed = $fixtures->subscribedFeed($user, 'https://de.example.com/feed.xml', FeedLanguage::German);
        $fixtures->favourite($user, $feed, 'Bundesliga heute');
        $fixtures->favourite($user, $feed, 'Bundesliga morgen');
        $fixtures->entries($feed, 600, 'Wetterbericht', YourStreamExplainFixtures::BACKGROUND_AT);
        $fixtures->entries($feed, 3, 'Bundesliga am Samstag', YourStreamExplainFixtures::TODAY_AT);
        $tester = new CommandTester($this->command($fixtures));

        $tester->execute(['--user' => (string) $user->requireId(), '--days' => '1']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('Bundesliga', $display);
        self::assertStringContainsString('widest', $display);
        self::assertStringContainsString('3 entries over 1 day', $display);
    }

    private function command(YourStreamExplainFixtures $fixtures): YourStreamExplainCommand
    {
        return new YourStreamExplainCommand(
            $fixtures->explainer($this->requireFromContainer(SavedSearchRepository::class)),
        );
    }
}
