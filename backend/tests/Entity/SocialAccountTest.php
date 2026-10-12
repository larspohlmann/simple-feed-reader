<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\SealedSecret;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialAccountStatus;
use App\Enum\SocialNetwork;
use PHPUnit\Framework\TestCase;

final class SocialAccountTest extends TestCase
{
    private function account(): SocialAccount
    {
        return new SocialAccount(
            new User('social-entity@example.test', new \DateTimeImmutable('2026-07-01 10:00:00')),
            SocialNetwork::Bluesky,
            'did:plc:alice',
            '@alice.bsky.social',
            'https://pds.example.test',
            new SealedSecret('c', 'n', 's', 1),
            'mnop',
            new \DateTimeImmutable('2026-10-12 10:00:00'),
        );
    }

    public function testANewAccountIsConnectedAndNotTheDefault(): void
    {
        $account = $this->account();

        self::assertSame(SocialAccountStatus::Connected, $account->getStatus());
        self::assertFalse($account->isDefault());
    }

    public function testReplacingTheCredentialReconnectsAndTakesTheCurrentHandle(): void
    {
        $account = $this->account();
        $account->markNeedsReconnect();

        $account->replaceCredential(
            '@alice.example.com',
            new SealedSecret('c2', 'n2', 's2', 1),
            'qrst',
            new \DateTimeImmutable('2026-10-13 10:00:00'),
        );

        self::assertSame(SocialAccountStatus::Connected, $account->getStatus());
        self::assertSame('@alice.example.com', $account->getHandle());
        self::assertSame('c2', $account->getSealedCredential()->ciphertext);
        self::assertSame('qrst', $account->getCredentialHint());
        self::assertSame('2026-10-13 10:00:00', $account->getConnectedAt()->format('Y-m-d H:i:s'));
    }

    public function testTheDefaultFlagFollowsItsTwoMutators(): void
    {
        $account = $this->account();

        $account->becomeDefault();
        self::assertTrue($account->isDefault());

        $account->stopBeingDefault();
        self::assertFalse($account->isDefault());
    }
}
