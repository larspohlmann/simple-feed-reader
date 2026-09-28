<?php

declare(strict_types=1);

namespace App\Tests\Service\Reading;

use App\Service\Reading\ReadScope;
use App\Service\Reading\ReadScopeKind;
use PHPUnit\Framework\TestCase;

final class ReadScopeTest extends TestCase
{
    public function testAllNamesNoSubscriptionOrTag(): void
    {
        $scope = ReadScope::all();

        self::assertSame(ReadScopeKind::All, $scope->kind);
        self::assertNull($scope->id);
    }

    public function testFeedCarriesItsSubscriptionId(): void
    {
        $scope = ReadScope::feed(7);

        self::assertSame(ReadScopeKind::Feed, $scope->kind);
        self::assertSame(7, $scope->id);
        self::assertSame(7, $scope->targetId());
    }

    public function testTagCarriesItsTagId(): void
    {
        $scope = ReadScope::tag(9);

        self::assertSame(ReadScopeKind::Tag, $scope->kind);
        self::assertSame(9, $scope->id);
        self::assertSame(9, $scope->targetId());
    }

    public function testAnAllScopeHasNoTargetId(): void
    {
        $this->expectException(\LogicException::class);

        ReadScope::all()->targetId();
    }
}
