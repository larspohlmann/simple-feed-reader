<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\ProfileFeedRule;

use App\Service\Social\Social\ProfileFeedRule\MastodonProfileFeedRule;
use PHPUnit\Framework\TestCase;

final class MastodonProfileFeedRuleTest extends TestCase
{
    public function testAnAccountGetsALeadingAt(): void
    {
        self::assertSame('@ada@mastodon.example', new MastodonProfileFeedRule()->formatHandle('ada@mastodon.example'));
    }

    public function testTheChannelTitleAlreadyCarriesOnlyTheDisplayName(): void
    {
        $title = new MastodonProfileFeedRule()->formatTitle(
            'https://mastodon.social/@Gargron.rss',
            '@Gargron - Eugen Rochko',
        );

        self::assertSame('@Gargron - Eugen Rochko', $title);
    }

    public function testNoFeedChangesOnExport(): void
    {
        self::assertNull(new MastodonProfileFeedRule()->buildExportUrl('social:mastodon:4:author:123'));
    }
}
