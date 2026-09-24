<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingest\Platform;

use App\Service\Ingest\Platform\PlatformEntryRules;
use App\Service\Ingest\Platform\RedditEntryRule;
use App\Service\Parser\ParsedEntry;
use PHPUnit\Framework\TestCase;

final class PlatformEntryRulesTest extends TestCase
{
    private function entry(string $url): ParsedEntry
    {
        return new ParsedEntry('g-1', $url, 'Title', null, null, '<p>body</p>', null);
    }

    public function testANonMatchingEntryComesBackUnchanged(): void
    {
        $rules = new PlatformEntryRules([new RedditEntryRule()]);
        $entry = $this->entry('https://example.com/post');

        self::assertSame($entry, $rules->apply($entry));
    }

    public function testAMatchingEntryIsRewrittenByItsRule(): void
    {
        $rules = new PlatformEntryRules([new RedditEntryRule()]);
        $entry = $this->entry('https://www.reddit.com/r/PHP/comments/1abc/t/');

        $result = $rules->apply($entry);

        self::assertNotSame($entry, $result);
        self::assertNull($result->url);
    }
}
