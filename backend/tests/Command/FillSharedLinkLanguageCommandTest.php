<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\LinkCardContent;
use App\Service\Content\LinkCard\Model\NewSharedLinkModel;
use App\Tests\DbTestCase;
use App\Tests\Service\Operations\LinkLanguageFill\SharedLinkLanguageFillTest;
use App\Tests\Support\PostEnrichers;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class FillSharedLinkLanguageCommandTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PostEnrichers::sharedLinks($this->entityManager)->insertMissing([
            self::link('french', SharedLinkLanguageFillTest::FRENCH),
            self::link('short', 'Court'),
        ]);
    }

    public function testItPrintsTheCounts(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString(
            'Filled 1 shared links (de 0, en 0, fr 1, es 0); 1 left without a language',
            $tester->getDisplay(),
        );
        self::assertSame('fr', $this->languageOf('french'));
    }

    public function testADryRunPrintsWhatItWouldFill(): void
    {
        $tester = $this->tester();

        $tester->execute(['--dry-run' => true]);

        self::assertStringContainsString(
            'Would fill 1 shared links (de 0, en 0, fr 1, es 0); 1 left',
            $tester->getDisplay(),
        );
        self::assertNull($this->languageOf('french'));
    }

    private static function link(string $hash, string $description): NewSharedLinkModel
    {
        return new NewSharedLinkModel(
            $hash,
            new LinkCardContent('https://example.com/' . $hash, 'Titre', $description, null, 'example.com'),
            null,
            new \DateTimeImmutable('2026-11-15 10:00:00'),
        );
    }

    private function languageOf(string $hash): mixed
    {
        return $this->entityManager->getConnection()->fetchOne(
            'SELECT language FROM shared_link WHERE url_hash = ?',
            [$hash],
        );
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());

        return new CommandTester($application->find('app:shared-links:fill-language'));
    }
}
