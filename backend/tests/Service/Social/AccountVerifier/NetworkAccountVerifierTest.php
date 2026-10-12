<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\AccountVerifier;

use App\Service\Social\AccountVerifier\NetworkAccountVerifier;
use App\Service\Social\Model\SocialCredentialsModel;
use App\Service\Social\Model\VerifiedAccountModel;
use App\Service\Social\NetworkVerifier\NetworkVerifierInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class NetworkAccountVerifierTest extends TestCase
{
    private function stubFor(VerifiedAccountModel $answer): NetworkVerifierInterface
    {
        return new class ($answer) implements NetworkVerifierInterface {
            public function __construct(private VerifiedAccountModel $answer)
            {
            }

            public static function network(): string
            {
                return 'stub';
            }

            public function verify(SocialCredentialsModel $credentials): VerifiedAccountModel
            {
                return $this->answer;
            }
        };
    }

    public function testEachNetworksCredentialsReachTheirOwnVerifier(): void
    {
        $bluesky = new VerifiedAccountModel('did:plc:alice', '@alice.bsky.social', 'https://pds.example.test');
        $mastodon = new VerifiedAccountModel(
            'lars@mastodon.example.test',
            '@lars@mastodon.example.test',
            'https://mastodon.example.test',
        );
        $verifier = new NetworkAccountVerifier(new ServiceLocator([
            'bluesky' => fn () => $this->stubFor($bluesky),
            'mastodon' => fn () => $this->stubFor($mastodon),
        ]));

        self::assertSame($bluesky, $verifier->verify(SocialCredentialsModel::bluesky('alice.bsky.social', 'pw')));
        self::assertSame(
            $mastodon,
            $verifier->verify(SocialCredentialsModel::mastodon('mastodon.example.test', 'token')),
        );
    }
}
