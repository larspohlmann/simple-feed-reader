<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\Model\ProfileVisibilityModel;
use App\Service\Social\ProfileVisibilityCache;
use App\Tests\Support\BlueskyProfiles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ProfileVisibilityCacheTest extends TestCase
{
    public function testAnAskedProfileIsRememberedAsHiddenOrPublic(): void
    {
        $cache = new ProfileVisibilityCache(new ArrayAdapter());
        $rusty = new HiddenProfileModel(BlueskyProfiles::RUSTY, 'Rusty');

        $cache->remember([BlueskyProfiles::RUSTY, 'did:plc:bob'], [BlueskyProfiles::RUSTY => $rusty]);

        self::assertEquals(ProfileVisibilityModel::hiddenProfile($rusty), $cache->visibilityOf(BlueskyProfiles::RUSTY));
        self::assertEquals(ProfileVisibilityModel::publicProfile(), $cache->visibilityOf('did:plc:bob'));
    }

    public function testAProfileNeverAskedIsUnknown(): void
    {
        $cache = new ProfileVisibilityCache(new ArrayAdapter());
        $cache->remember(['did:plc:bob'], ['did:plc:carol' => new HiddenProfileModel('did:plc:carol', 'Carol')]);

        self::assertEquals(ProfileVisibilityModel::unknown(), $cache->visibilityOf('did:plc:carol'));
        self::assertEquals(ProfileVisibilityModel::unknown(), $cache->visibilityOf('did:plc:dave'));
    }
}
