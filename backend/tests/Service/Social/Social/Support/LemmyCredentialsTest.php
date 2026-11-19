<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Support;

use App\Enum\SocialNetwork;
use App\Service\Social\Social\Exception\InvalidInstanceUrlException;
use App\Service\Social\Social\Model\CredentialReplacementModel;
use App\Service\Social\Social\Support\LemmyCredentials;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;

final class LemmyCredentialsTest extends TestCase
{
    public function testALemmyLoginKeepsThePasswordUntrimmedAndLeavesNoHint(): void
    {
        $credentials = LemmyCredentials::buildCredentials('lemmy.example', 'lars', ' pass word ', null);

        self::assertSame(SocialNetwork::Lemmy, $credentials->network);
        self::assertSame('https://lemmy.example', $credentials->address);
        self::assertSame(' pass word ', $credentials->secret);
        self::assertSame('lars', $credentials->login);
        self::assertNull($credentials->secondFactor);
        self::assertSame('', $credentials->hint);
    }

    public function testALemmyInstanceOverPlainHttpIsRefused(): void
    {
        $this->expectException(InvalidInstanceUrlException::class);

        LemmyCredentials::buildCredentials('http://lemmy.example', 'lars', 'pw', null);
    }

    public function testReconnectingALemmyAccountLogsInAsItsStoredUser(): void
    {
        $account = SocialAccounts::lemmyOf(SocialAccounts::owner());

        $credentials = LemmyCredentials::buildReconnectCredentials(
            $account,
            new CredentialReplacementModel('pw', '123456'),
        );

        self::assertSame(SocialNetwork::Lemmy, $credentials->network);
        self::assertSame('lars', $credentials->login);
        self::assertSame('123456', $credentials->secondFactor);
        self::assertSame('https://lemmy.example', $credentials->address);
        self::assertSame('pw', $credentials->secret);
        self::assertSame('', $credentials->hint);
    }
}
