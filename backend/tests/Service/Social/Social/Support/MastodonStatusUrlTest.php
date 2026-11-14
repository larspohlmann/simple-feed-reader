<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Support;

use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Social\Support\MastodonStatusUrl;
use PHPUnit\Framework\TestCase;

final class MastodonStatusUrlTest extends TestCase
{
    public function testTheWebUrlIsPreferred(): void
    {
        $status = JsonNodeModel::of([
            'url' => 'https://a.example/@x/1',
            'uri' => 'https://a.example/users/x/statuses/1',
        ]);

        self::assertSame('https://a.example/@x/1', MastodonStatusUrl::of($status));
    }

    public function testTheUriServesWhenThereIsNoUsableUrl(): void
    {
        $status = JsonNodeModel::of(['url' => 'not a url', 'uri' => 'https://a.example/users/x/statuses/1']);

        self::assertSame('https://a.example/users/x/statuses/1', MastodonStatusUrl::of($status));
    }

    public function testAStatusWithNeitherHasNoUrl(): void
    {
        self::assertNull(MastodonStatusUrl::of(JsonNodeModel::of(['uri' => 'tag:a.example,2026:1'])));
    }
}
