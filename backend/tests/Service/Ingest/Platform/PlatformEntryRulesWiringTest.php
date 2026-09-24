<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingest\Platform;

use App\Service\Ingest\Platform\PlatformEntryRules;
use App\Service\Parser\ParsedEntry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlatformEntryRulesWiringTest extends KernelTestCase
{
    public function testTheTaggedRedditRuleResolvesThroughTheContainer(): void
    {
        self::bootKernel();
        $rules = self::getContainer()->get(PlatformEntryRules::class);
        self::assertInstanceOf(PlatformEntryRules::class, $rules);

        $thread = new ParsedEntry(
            't3_1abc',
            'https://www.reddit.com/r/PHP/comments/1abc/t/',
            'Title',
            null,
            null,
            '<p>body</p>',
            null,
        );

        $result = $rules->apply($thread);

        self::assertNull($result->url);
    }
}
