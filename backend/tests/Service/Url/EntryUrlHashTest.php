<?php

declare(strict_types=1);

namespace App\Tests\Service\Url;

use App\Service\Bluesky\BlueskyEntryIdentityUrl;
use App\Service\Url\EntryUrlHash;
use App\Service\Url\UrlNormalizer;
use App\Tests\Support\EntryUrlHashes;
use PHPUnit\Framework\TestCase;

final class EntryUrlHashTest extends TestCase
{
    private const string POST_GUID = 'at://did:plc:xjc7mkotpenvhh6ffewkdpyn/app.bsky.feed.post/3mz7vexdd5c2n';

    public function testATimelineCopyAndAnRssCopyOfOnePostShareTheHash(): void
    {
        $hash = EntryUrlHashes::production();

        self::assertSame(
            $hash->of(self::POST_GUID, 'https://bsky.app/profile/did:plc:xjc7mkotpenvhh6ffewkdpyn/post/3mz7vexdd5c2n'),
            $hash->of(self::POST_GUID, 'https://bsky.app/profile/meidastouch.com/post/3mz7vexdd5c2n'),
        );
    }

    public function testAnIdentityUrlHashesEvenWithoutALink(): void
    {
        $hash = EntryUrlHashes::production();

        self::assertSame(
            hash('sha256', 'https://bsky.app/profile/did:plc:xjc7mkotpenvhh6ffewkdpyn/post/3mz7vexdd5c2n'),
            $hash->of(self::POST_GUID, null),
        );
    }

    public function testAnyOtherGuidHashesItsNormalizedLink(): void
    {
        $normalizer = new UrlNormalizer();
        $hash = new EntryUrlHash($normalizer, [new BlueskyEntryIdentityUrl()]);
        $url = 'https://example.com/story?utm_source=feed';

        self::assertSame($normalizer->hash($url), $hash->of('https://example.com/story', $url));
    }

    public function testAnUnlinkedEntryWithAPlainGuidHasNoHash(): void
    {
        self::assertNull((new EntryUrlHash(new UrlNormalizer(), []))->of('only-guid', null));
    }
}
