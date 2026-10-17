<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\Model\ProfileVisibilityModel;
use App\Service\Social\ProfileVisibilityCache;
use App\Tests\Support\BlueskyProfiles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

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

    public function testTheAnswersReachTheStoreAnotherProcessReads(): void
    {
        $directory = sys_get_temp_dir() . '/profile-visibility-' . bin2hex(random_bytes(8));
        $writer = new FilesystemAdapter('', 0, $directory);
        (new ProfileVisibilityCache($writer))->remember(['did:plc:bob'], []);

        $reader = new ProfileVisibilityCache(new FilesystemAdapter('', 0, $directory));

        self::assertEquals(ProfileVisibilityModel::publicProfile(), $reader->visibilityOf('did:plc:bob'));
        $writer->clear();
    }

    public function testAProfileNeverAskedIsUnknown(): void
    {
        $cache = new ProfileVisibilityCache(new ArrayAdapter());
        $cache->remember(['did:plc:bob'], ['did:plc:carol' => new HiddenProfileModel('did:plc:carol', 'Carol')]);

        self::assertEquals(ProfileVisibilityModel::unknown(), $cache->visibilityOf('did:plc:carol'));
        self::assertEquals(ProfileVisibilityModel::unknown(), $cache->visibilityOf('did:plc:dave'));
    }
}
