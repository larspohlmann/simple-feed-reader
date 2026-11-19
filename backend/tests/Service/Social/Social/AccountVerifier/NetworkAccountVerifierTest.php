<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\AccountVerifier;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Social\AccountVerifier\NetworkAccountVerifier;
use App\Service\Social\Social\Model\CredentialReplacementModel;
use App\Service\Social\Social\Model\SocialCredentialsModel;
use App\Service\Social\Social\Model\VerifiedAccountModel;
use App\Service\Social\Social\NetworkVerifier\NetworkVerifierInterface;
use App\Service\Social\Social\Support\BlueskyCredentials;
use App\Service\Social\Social\Support\MastodonCredentials;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class NetworkAccountVerifierTest extends TestCase
{
    private function stubFor(VerifiedAccountModel $answer, SocialCredentialsModel $reconnect): NetworkVerifierInterface
    {
        return new class ($answer, $reconnect) implements NetworkVerifierInterface {
            public function __construct(
                private readonly VerifiedAccountModel $answer,
                private readonly SocialCredentialsModel $reconnect,
            ) {
            }

            public function verify(SocialCredentialsModel $credentials): VerifiedAccountModel
            {
                return $this->answer;
            }

            public function buildReconnectCredentials(
                SocialAccount $account,
                CredentialReplacementModel $replacement,
            ): SocialCredentialsModel {
                return $this->reconnect;
            }
        };
    }

    public function testEachNetworksCredentialsReachTheirOwnVerifier(): void
    {
        $bluesky = new VerifiedAccountModel(
            'did:plc:alice',
            '@alice.bsky.social',
            'https://pds.example.test',
            'abcd-efgh-ijkl-mnop',
        );
        $mastodon = new VerifiedAccountModel(
            'lars@mastodon.example.test',
            '@lars@mastodon.example.test',
            'https://mastodon.example.test',
            'token-1234',
        );
        $verifier = $this->verifier($bluesky, $mastodon);

        self::assertSame(
            $bluesky,
            $verifier->verify(BlueskyCredentials::buildCredentials('alice.bsky.social', 'abcd-efgh-ijkl-mnop')),
        );
        self::assertSame(
            $mastodon,
            $verifier->verify(MastodonCredentials::buildCredentials('mastodon.example.test', 'token')),
        );
    }

    public function testEachAccountsReconnectCredentialsAreBuiltByItsOwnNetwork(): void
    {
        $verified = new VerifiedAccountModel('did:plc:alice', '@alice.bsky.social', 'https://pds.example.test', 'x');
        $verifier = $this->verifier($verified, $verified);
        $replacement = new CredentialReplacementModel('wxyz-wxyz-wxyz-9999', null);

        self::assertSame(
            SocialNetwork::Bluesky,
            $verifier->buildReconnectCredentials(SocialAccounts::bluesky(), $replacement)->network,
        );
        self::assertSame(
            SocialNetwork::Mastodon,
            $verifier->buildReconnectCredentials(SocialAccounts::mastodon(), $replacement)->network,
        );
    }

    private function verifier(VerifiedAccountModel $bluesky, VerifiedAccountModel $mastodon): NetworkAccountVerifier
    {
        $blueskyReconnect = new SocialCredentialsModel(SocialNetwork::Bluesky, 'did:plc:alice', 'secret', 'cret');
        $mastodonReconnect = new SocialCredentialsModel(
            SocialNetwork::Mastodon,
            'https://mastodon.example',
            'secret',
            'cret',
        );

        return new NetworkAccountVerifier(new ServiceLocator([
            'bluesky' => fn () => $this->stubFor($bluesky, $blueskyReconnect),
            'mastodon' => fn () => $this->stubFor($mastodon, $mastodonReconnect),
        ]));
    }
}
