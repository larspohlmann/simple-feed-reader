<?php

declare(strict_types=1);

namespace App\Tests\Service\Foundation\CanonicalItem;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Enum\CanonicalItemKind;
use App\Service\Foundation\CanonicalItem\CanonicalKeyRule\CanonicalKeyRuleInterface;
use App\Service\Foundation\CanonicalItem\CanonicalKeys;
use App\Service\Foundation\CanonicalItem\Model\CanonicalKeyModel;
use PHPUnit\Framework\TestCase;

final class CanonicalKeysTest extends TestCase
{
    public function testFirstAnsweringRuleWins(): void
    {
        $first = new CanonicalKeyModel(CanonicalItemKind::Mastodon, 'first');
        $second = new CanonicalKeyModel(CanonicalItemKind::Lemmy, 'second');

        $keys = new CanonicalKeys([
            $this->buildRuleAnswering(null),
            $this->buildRuleAnswering($first),
            $this->buildRuleAnswering($second),
        ]);

        self::assertSame($first, $keys->findKey($this->buildEntry()));
    }

    public function testNoRuleAnswersGivesNull(): void
    {
        self::assertNull(new CanonicalKeys([$this->buildRuleAnswering(null)])->findKey($this->buildEntry()));
    }

    public function testKeyHashIsTheSha256OfTheKey(): void
    {
        $key = new CanonicalKeyModel(CanonicalItemKind::Bluesky, 'at://did:plc:abc/app.bsky.feed.post/3k');

        self::assertSame(hash('sha256', 'at://did:plc:abc/app.bsky.feed.post/3k'), $key->keyHash());
        self::assertSame(64, \strlen($key->keyHash()));
    }

    private function buildRuleAnswering(?CanonicalKeyModel $key): CanonicalKeyRuleInterface
    {
        return new readonly class ($key) implements CanonicalKeyRuleInterface {
            public function __construct(private ?CanonicalKeyModel $key)
            {
            }

            public function findKey(Entry $entry): ?CanonicalKeyModel
            {
                return $this->key;
            }
        };
    }

    private function buildEntry(): Entry
    {
        $now = new \DateTimeImmutable('2026-01-01');

        return new Entry(new Feed('https://example.com/feed'), 'guid', null, 'Title', $now, $now);
    }
}
