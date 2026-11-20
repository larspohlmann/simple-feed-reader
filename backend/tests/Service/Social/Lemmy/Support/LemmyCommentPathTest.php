<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Lemmy\Support;

use App\Service\Social\Lemmy\Support\LemmyCommentPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LemmyCommentPathTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string}> */
    public static function paths(): iterable
    {
        yield 'a reply to the post' => ['0.26906406', null];
        yield 'a reply to a comment' => ['0.26906403.26906408', '26906403'];
        yield 'a deeper reply' => ['0.1.2.3', '2'];
        yield 'no path' => [null, null];
        yield 'an empty path' => ['', null];
        yield 'the root alone' => ['0', null];
        yield 'a malformed segment' => ['0.x.5', null];
    }

    #[DataProvider('paths')]
    public function testParentOf(?string $path, ?string $parent): void
    {
        self::assertSame($parent, LemmyCommentPath::parseParentId($path));
    }
}
