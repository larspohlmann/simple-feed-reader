<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Enum\NetworkFeedKind;
use App\Enum\SocialNetwork;
use App\Exception\ValidationException;
use App\Repository\NetworkScope;
use PHPUnit\Framework\TestCase;

final class NetworkScopeTest extends TestCase
{
    public function testNoNetworkAndNoFeedKindIsNoScope(): void
    {
        self::assertNull(NetworkScope::fromRequestValues(null, null));
    }

    public function testANetworkAloneScopesTheWholeNetwork(): void
    {
        $scope = NetworkScope::fromRequestValues('bluesky', null);

        self::assertEquals(NetworkScope::whole(SocialNetwork::Bluesky), $scope);
        self::assertNull($scope?->feedKind);
    }

    public function testAProfileFeedKindScopesTheNetworksProfiles(): void
    {
        $scope = NetworkScope::fromRequestValues('mastodon', 'profile');

        self::assertSame(SocialNetwork::Mastodon, $scope?->network);
        self::assertSame(NetworkFeedKind::Profile, $scope->feedKind);
    }

    public function testAnUnknownFeedKindIsAValidationError(): void
    {
        $this->assertRefused(
            ['feedKind' => ['Unknown feedKind. Use one of: profile.']],
            static fn () => NetworkScope::fromRequestValues('bluesky', 'home'),
        );
    }

    public function testAFeedKindWithoutANetworkIsAValidationError(): void
    {
        $this->assertRefused(
            ['feedKind' => ['A feedKind needs a network.']],
            static fn () => NetworkScope::fromRequestValues(null, 'profile'),
        );
    }

    public function testTheRunKeyTellsAWholeNetworkFromItsProfiles(): void
    {
        self::assertSame('bluesky', NetworkScope::whole(SocialNetwork::Bluesky)->toRunKey());
        self::assertSame('bluesky-profile', NetworkScope::profiles(SocialNetwork::Bluesky)->toRunKey());
    }

    /** @param array<string, list<string>> $expected */
    private function assertRefused(array $expected, callable $parse): void
    {
        try {
            $parse();
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertSame($expected, $exception->errors);
        }
    }
}
