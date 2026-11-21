<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Ai;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Curation\Ai\ConnectionPicks;
use App\Service\Curation\Ai\Exception\ConnectionPickRejectedException;
use App\Tests\DbTestCase;
use App\Tests\Support\AiProviderSettingsFactory;
use App\Tests\Support\SeedsUsers;

final class ConnectionPicksTest extends DbTestCase
{
    use SeedsUsers;

    private function persistConnection(User $user): AiProviderSettings
    {
        $connection = AiProviderSettingsFactory::build($user);
        $this->entityManager->persist($connection);
        $this->entityManager->flush();

        return $connection;
    }

    private function connectionPicks(): ConnectionPicks
    {
        /** @var ConnectionPicks $connectionPicks */
        $connectionPicks = self::getContainer()->get(ConnectionPicks::class);

        return $connectionPicks;
    }

    public function testNoIdIsNoPick(): void
    {
        $user = $this->user('picks-none@example.test');

        $pick = $this->connectionPicks()->requireAllowedPickIfSet($user, null, null, static fn (): bool => true);

        self::assertNull($pick);
    }

    public function testAConnectionThatCanServeIsAllowed(): void
    {
        $user = $this->user('picks-serving@example.test');
        $connection = $this->persistConnection($user);

        $pick = $this->connectionPicks()->requireAllowedPickIfSet(
            $user,
            $connection->requireId(),
            null,
            static fn (): bool => true,
        );

        self::assertSame($connection, $pick);
    }

    public function testTheStoredPickStaysAllowedAfterItStoppedServing(): void
    {
        $user = $this->user('picks-stored@example.test');
        $connection = $this->persistConnection($user);

        $pick = $this->connectionPicks()->requireAllowedPickIfSet(
            $user,
            $connection->requireId(),
            $connection,
            static fn (): bool => false,
        );

        self::assertSame($connection, $pick);
    }

    public function testAnotherConnectionThatCannotServeIsRejected(): void
    {
        $user = $this->user('picks-rejected@example.test');
        $storedPick = $this->persistConnection($user);
        $connection = $this->persistConnection($user);

        $this->expectException(ConnectionPickRejectedException::class);
        $this->connectionPicks()->requireAllowedPickIfSet(
            $user,
            $connection->requireId(),
            $storedPick,
            static fn (): bool => false,
        );
    }
}
