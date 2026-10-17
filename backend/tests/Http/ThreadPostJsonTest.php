<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\ThreadPostJson;
use App\Service\Social\Model\ThreadPostModel;
use PHPUnit\Framework\TestCase;

final class ThreadPostJsonTest extends TestCase
{
    public function testAnUndatedReplyHasNoPublishedTime(): void
    {
        $json = ThreadPostJson::of(new ThreadPostModel('https://x/1', null, '<p>one</p>', [], null));

        self::assertNull($json['publishedAt']);
        self::assertNull($json['linkCard']);
    }
}
