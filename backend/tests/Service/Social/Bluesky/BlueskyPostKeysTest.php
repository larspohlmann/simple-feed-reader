<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Bluesky;

use App\Service\Social\Bluesky\Model\BlueskyPostKeyModel;
use App\Service\Social\Bluesky\Support\BlueskyTid;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class BlueskyPostKeysTest extends TestCase
{
    use AssignsEntityIds;

    public function testSameKeyYieldsTheSameRecordKey(): void
    {
        $postKeys = SocialApiWiring::blueskyPostKeys(new ArrayAdapter(), '2026-11-17T13:00:00+01:00');
        $account = SocialApiWiring::blueskyAccount();

        $first = $postKeys->recordKeyFor($account, 'key-1');
        $second = $postKeys->recordKeyFor($account, 'key-1');

        $recordKey = BlueskyTid::at(new \DateTimeImmutable('2026-11-17T12:00:00Z'), crc32('key-1'));
        self::assertEquals(new BlueskyPostKeyModel($recordKey, seenBefore: false), $first);
        self::assertEquals(new BlueskyPostKeyModel($recordKey, seenBefore: true), $second);
    }

    public function testOtherAccountOrKeyYieldsAnother(): void
    {
        $postKeys = SocialApiWiring::blueskyPostKeys(new ArrayAdapter());
        $account = SocialApiWiring::blueskyAccount();
        $otherAccount = self::withId(SocialAccounts::bluesky(), 4);

        $first = $postKeys->recordKeyFor($account, 'key-1');
        $otherKey = $postKeys->recordKeyFor($account, 'key-2');
        $onOtherAccount = $postKeys->recordKeyFor($otherAccount, 'key-1');

        self::assertNotSame($first->recordKey, $otherKey->recordKey);
        self::assertFalse($otherKey->seenBefore);
        self::assertFalse($onOtherAccount->seenBefore);
    }
}
