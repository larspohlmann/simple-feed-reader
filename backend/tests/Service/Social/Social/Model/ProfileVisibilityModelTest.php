<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Model;

use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\Model\ProfileVisibilityModel;
use PHPUnit\Framework\TestCase;

final class ProfileVisibilityModelTest extends TestCase
{
    public function testOnlyAProfileFoundPublicIsPublic(): void
    {
        self::assertTrue(ProfileVisibilityModel::publicProfile()->isPublic());
        self::assertFalse(ProfileVisibilityModel::unknown()->isPublic());
        self::assertFalse(
            ProfileVisibilityModel::hiddenProfile(new HiddenProfileModel('did:plc:bob', 'Bob'))->isPublic(),
        );
    }

    public function testOnlyAProfileNeverFoundEitherWayIsUnknown(): void
    {
        self::assertTrue(ProfileVisibilityModel::unknown()->isUnknown());
        self::assertFalse(ProfileVisibilityModel::publicProfile()->isUnknown());
        self::assertFalse(
            ProfileVisibilityModel::hiddenProfile(new HiddenProfileModel('did:plc:bob', 'Bob'))->isUnknown(),
        );
    }
}
