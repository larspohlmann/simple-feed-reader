<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Lemmy\Support;

use App\Service\Foundation\Url\Model\LemmyPostPageModel;
use App\Service\Social\Lemmy\Support\LemmyPostKey;
use PHPUnit\Framework\TestCase;

final class LemmyPostKeyTest extends TestCase
{
    public function testApIdWithUpperCaseHostAndSlashIsThePostKey(): void
    {
        $key = LemmyPostKey::parseKey('https://Lemmy.Example/post/42/');

        self::assertSame('https://lemmy.example/post/42', $key?->key);
    }

    public function testNoPostAddressIsNoKey(): void
    {
        self::assertNull(LemmyPostKey::parseKey('https://lemmy.example/c/news'));
        self::assertNull(LemmyPostKey::parseKey(null));
    }

    public function testPageAndAddressShareOneKey(): void
    {
        $fromAddress = LemmyPostKey::parseKey('https://lemmy.example/post/42');
        $fromPage = LemmyPostKey::fromPage(new LemmyPostPageModel('lemmy.example', 42));

        self::assertSame($fromPage->keyHash(), $fromAddress?->keyHash());
    }

    public function testAPostAddressStaysTheResolvedPage(): void
    {
        self::assertSame(
            'https://lemmy.other/post/9',
            LemmyPostKey::resolvePostPage('https://lemmy.other/post/9', 'https://lemmy.example', 3),
        );
    }

    public function testANonLemmyAddressResolvesToTheHomeInstancePostPage(): void
    {
        self::assertSame(
            'https://lemmy.example/post/3',
            LemmyPostKey::resolvePostPage('https://mbin.example/m/php/t/1', 'https://lemmy.example/', 3),
        );
        self::assertSame(
            'https://lemmy.example/post/3',
            LemmyPostKey::resolvePostPage(null, 'https://lemmy.example/', 3),
        );
    }
}
