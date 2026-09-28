<?php

declare(strict_types=1);

namespace App\Tests\Service\Reading\Model;

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

    public function testAnAllScopeHasNoTargetId(): void
    {
        $this->expectException(\LogicException::class);

        ReadScopeModel::all()->targetId();
    }
}
