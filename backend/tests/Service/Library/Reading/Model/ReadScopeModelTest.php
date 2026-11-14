<?php

declare(strict_types=1);

namespace App\Tests\Service\Reading\Model;

use App\Enum\SocialNetwork;
use App\Repository\NetworkScope;
use App\Service\Reading\Model\ReadScopeKind;
use App\Service\Reading\Model\ReadScopeModel;
use PHPUnit\Framework\TestCase;

final class ReadScopeModelTest extends TestCase
{
    public function testAllNamesNoSubscriptionOrTag(): void
    {
        $scope = ReadScopeModel::all();

        self::assertSame(ReadScopeKind::All, $scope->kind);
    }

    public function testFeedCarriesItsSubscriptionId(): void
    {
        $scope = ReadScopeModel::feed(7);

        self::assertSame(ReadScopeKind::Feed, $scope->kind);
        self::assertSame(7, $scope->targetId());
    }

    public function testTagCarriesItsTagId(): void
    {
        $scope = ReadScopeModel::tag(9);

        self::assertSame(ReadScopeKind::Tag, $scope->kind);
        self::assertSame(9, $scope->targetId());
    }

    public function testNetworkCarriesItsNetwork(): void
    {
        $scope = ReadScopeModel::network(NetworkScope::whole(SocialNetwork::Mastodon));

        self::assertSame(ReadScopeKind::Network, $scope->kind);
        self::assertEquals(NetworkScope::whole(SocialNetwork::Mastodon), $scope->targetNetworkScope());
    }

    public function testANetworkScopeHasNoTargetId(): void
    {
        $this->expectException(\LogicException::class);

        ReadScopeModel::network(NetworkScope::whole(SocialNetwork::Bluesky))->targetId();
    }

    public function testATagScopeNamesNoNetwork(): void
    {
        $this->expectException(\LogicException::class);

        ReadScopeModel::tag(9)->targetNetworkScope();
    }

    public function testAnAllScopeHasNoTargetId(): void
    {
        $this->expectException(\LogicException::class);

        ReadScopeModel::all()->targetId();
    }
}
