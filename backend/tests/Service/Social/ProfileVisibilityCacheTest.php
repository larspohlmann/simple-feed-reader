<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\ProfileVisibilityCache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ProfileVisibilityCacheTest extends TestCase
{
    private const string RUSTY = 'did:plc:hczfd45prvqtdrw77256syag';

    public function testAnAskedProfileIsRememberedAsHiddenOrPublic(): void
    {
        $cache = new ProfileVisibilityCache(new ArrayAdapter());
        $rusty = new HiddenProfileModel(self::RUSTY, 'Rusty');

        $cache->remember([self::RUSTY, 'did:plc:bob'], [self::RUSTY => $rusty]);

        self::assertEquals($rusty, $cache->hiddenOf(self::RUSTY));
        self::assertFalse($cache->isPublic(self::RUSTY));
        self::assertTrue($cache->isPublic('did:plc:bob'));
        self::assertNull($cache->hiddenOf('did:plc:bob'));
    }

    public function testAProfileNeverAskedIsNeitherHiddenNorPublic(): void
    {
        $cache = new ProfileVisibilityCache(new ArrayAdapter());
        $cache->remember(['did:plc:bob'], ['did:plc:carol' => new HiddenProfileModel('did:plc:carol', 'Carol')]);

        self::assertFalse($cache->isPublic('did:plc:carol'));
        self::assertNull($cache->hiddenOf('did:plc:carol'));
        self::assertFalse($cache->isPublic('did:plc:dave'));
    }
}
