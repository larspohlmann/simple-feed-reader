<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Entity\Label;
use App\Entity\User;
use App\Tests\DbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class UserDefaultLabelListenerTest extends DbTestCase
{
    public function testNewUserGetsReadLaterInEnglish(): void
    {
        $user = $this->persistUserWithLocale('en');

        self::assertSame(['Read later'], $this->labelNamesOf($user));
    }

    #[DataProvider('provideLocalizedNames')]
    public function testNewUserGetsReadLaterInTheirLocale(string $locale, string $expectedName): void
    {
        $user = $this->persistUserWithLocale($locale);

        self::assertSame([$expectedName], $this->labelNamesOf($user));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideLocalizedNames(): iterable
    {
        yield 'german' => ['de', 'Später lesen'];
        yield 'french' => ['fr', 'À lire plus tard'];
        yield 'spanish' => ['es', 'Leer más tarde'];
    }

    public function testPersistingAManagedAccountAgainAddsNoSecondLabel(): void
    {
        $user = $this->persistUserWithLocale('en');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        self::assertSame(1, $this->entityManager->getRepository(Label::class)->count(['user' => $user]));
    }

    private function persistUserWithLocale(string $locale): User
    {
        $user = new User($locale . '-label@example.test', new \DateTimeImmutable('2026-11-27 09:00:00'));
        $user->setLocale($locale);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * @return list<string>
     */
    private function labelNamesOf(User $user): array
    {
        $this->entityManager->clear();

        return array_map(
            static fn (Label $label): string => $label->getName(),
            $this->entityManager->getRepository(Label::class)->findBy(['user' => $user->requireId()]),
        );
    }
}
