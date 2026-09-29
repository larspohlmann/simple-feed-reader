<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Repository\UserRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ResetUserPasswordCommandTest extends DbTestCase
{
    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());

        return new CommandTester($application->find('app:user:reset-password'));
    }

    private function hasher(): UserPasswordHasherInterface
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return $hasher;
    }

    private function repository(): UserRepository
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        return $repository;
    }

    public function testGenerateResetsAnExistingUserAndPrintsThePassword(): void
    {
        $hasher = $this->hasher();
        (new UserFactory($this->entityManager, $hasher))->create('member@example.com', password: 'the-old-password');

        $tester = $this->tester();
        $tester->execute(['email' => 'member@example.com', '--generate' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/New password:\s*\S+/', $display);

        \preg_match('/New password:\s*(\S+)/', $display, $matches);
        $generated = $matches[1] ?? '';

        $user = $this->repository()->findOneByEmail('member@example.com');
        self::assertNotNull($user);
        self::assertTrue($hasher->isPasswordValid($user, $generated));
        self::assertFalse($hasher->isPasswordValid($user, 'the-old-password'));
    }

    public function testUnknownEmailFails(): void
    {
        $tester = $this->tester();
        $tester->execute(['email' => 'nobody@example.com', '--generate' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No account', $tester->getDisplay());
    }

    public function testAPromptedPasswordOfTwelveCharactersIsSet(): void
    {
        $hasher = $this->hasher();
        (new UserFactory($this->entityManager, $hasher))->create('prompted@example.com', password: 'the-old-password');

        $tester = $this->tester();
        $tester->setInputs(['twelve-chars']);
        $tester->execute(['email' => 'prompted@example.com']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $user = $this->repository()->findOneByEmail('prompted@example.com');
        self::assertNotNull($user);
        self::assertTrue($hasher->isPasswordValid($user, 'twelve-chars'));
    }

    public function testAPromptedPasswordOfElevenCharactersIsRefused(): void
    {
        $hasher = $this->hasher();
        (new UserFactory($this->entityManager, $hasher))->create('too-short@example.com', password: 'the-old-password');

        $tester = $this->tester();
        $tester->setInputs(['eleven-char']);
        $tester->execute(['email' => 'too-short@example.com']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('at least 12 characters', $tester->getDisplay());
        $user = $this->repository()->findOneByEmail('too-short@example.com');
        self::assertNotNull($user);
        self::assertTrue($hasher->isPasswordValid($user, 'the-old-password'));
    }
}
