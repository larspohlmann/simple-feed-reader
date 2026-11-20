<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Mastodon\Support;

use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Social\Mastodon\Support\MastodonStatusUrl;
use PHPUnit\Framework\TestCase;

final class MastodonStatusUrlTest extends TestCase
{
    public function testTheWebUrlIsPreferred(): void
    {
        $status = JsonNodeModel::fromValue([
            'url' => 'https://a.example/@x/1',
            'uri' => 'https://a.example/users/x/statuses/1',
        ]);

        self::assertSame('https://a.example/@x/1', MastodonStatusUrl::resolveUrl($status));
    }

    public function testTheUriServesWhenThereIsNoUsableUrl(): void
    {
        $status = JsonNodeModel::fromValue(['url' => 'not a url', 'uri' => 'https://a.example/users/x/statuses/1']);

        self::assertSame('https://a.example/users/x/statuses/1', MastodonStatusUrl::resolveUrl($status));
    }

    public function testAStatusWithNeitherHasNoUrl(): void
    {
        self::assertNull(MastodonStatusUrl::resolveUrl(JsonNodeModel::fromValue(['uri' => 'tag:a.example,2026:1'])));
    }
}
