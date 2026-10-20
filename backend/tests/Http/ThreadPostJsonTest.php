<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Service\Social\Model\ThreadPostModel;
use PHPUnit\Framework\TestCase;
use App\Tests\Support\JsonMappers;

final class ThreadPostJsonTest extends TestCase
{
    public function testAnUndatedReplyHasNoPublishedTime(): void
    {
        $json = JsonMappers::threadPostJson()->of(new ThreadPostModel('https://x/1', null, '<p>one</p>', [], null));

        self::assertNull($json['publishedAt']);
        self::assertNull($json['linkCard']);
    }
}
