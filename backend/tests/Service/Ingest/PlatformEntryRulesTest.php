<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingest;

use App\Service\Ingest\PlatformEntryRule\RedditEntryRule;
use App\Service\Ingest\PlatformEntryRules;
use App\Service\Parser\Model\ParsedEntryModel;
use PHPUnit\Framework\TestCase;

final class PlatformEntryRulesTest extends TestCase
{
    private function entry(string $url): ParsedEntryModel
    {
        return new ParsedEntryModel('g-1', $url, 'Title', null, null, '<p>body</p>', null);
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
