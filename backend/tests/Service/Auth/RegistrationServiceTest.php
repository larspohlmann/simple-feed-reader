<?php

declare(strict_types=1);

namespace App\Tests\Service\Auth;

use App\Repository\UserRepository;
use App\Service\Auth\ActionTokenService;
use App\Service\Auth\RegistrationService;
use App\Service\Mail\AccountMailer;
use App\Tests\DbTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RegistrationServiceTest extends DbTestCase
{
    /**
     * Builds the service against a repository that always reports "no such
     * user". That is exactly what two concurrent requests for the same fresh
     * address observe: both SELECT before either INSERT commits, so both pass
     * the duplicate check and both go on to insert.
     *
     * The HTTP layer cannot stage this - one PHP process handles one request at
     * a time - so the race is reproduced at the seam where it actually occurs.
     */
    private function serviceWithBlindDuplicateCheck(): RegistrationService
    {
        $container = self::getContainer();

        $blindRepository = $this->createStub(UserRepository::class);
        $blindRepository->method('findOneByEmail')->willReturn(null);

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var ActionTokenService $tokens */
        $tokens = $container->get(ActionTokenService::class);
        /** @var AccountMailer $mailer */
        $mailer = $container->get(AccountMailer::class);
        /** @var ClockInterface $clock */
        $clock = $container->get(ClockInterface::class);

        return new RegistrationService($this->em, $blindRepository, $hasher, $tokens, $mailer, $clock);
    }

    /**
     * The production scenario is a double-clicked submit button, which is
     * common enough to be a certainty rather than a risk. Losing the race must
     * look to the client exactly like winning it: anything else is both a 500
     * on a normal user action and a crack in the enumeration guarantee, since
     * a distinguishable response tells the caller a concurrent signup for that
     * address was in flight.
     */
    public function testLosingTheInsertRaceIsIndistinguishableFromWinningIt(): void
    {
        $service = $this->serviceWithBlindDuplicateCheck();

        $service->register('race@example.com', 'correct-horse-battery');

        // Must not throw. The unique index is the authority on who won; the
        // loser's job is to say nothing and let the winner's mail stand.
        $service->register('race@example.com', 'correct-horse-battery');

        // Counted over a separate connection: the losing flush closes the
        // EntityManager, so the ORM cannot be asked anything afterwards.
        self::assertSame(1, $this->countUsersWithEmail('race@example.com'));
    }

    private function countUsersWithEmail(string $email): int
    {
        $count = $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM app_user WHERE email = ?',
            [$email],
        );
        self::assertIsNumeric($count);

        return (int) $count;
    }
}
