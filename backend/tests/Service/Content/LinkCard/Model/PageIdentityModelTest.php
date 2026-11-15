<?php

declare(strict_types=1);

namespace App\Tests\Service\Content\LinkCard\Model;

use App\Service\Content\LinkCard\Model\PageIdentityModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PageIdentityModelTest extends TestCase
{
    private const string PAGE = 'https://news.example/a';
    private const string CANONICAL = 'https://news.example/canonical';

    /** @return iterable<string, array{PageIdentityModel, bool}> */
    public static function others(): iterable
    {
        yield 'the same landing' => [new PageIdentityModel(self::PAGE, null, 'Story'), true];
        yield 'a landing that differs only by trackers' => [
            new PageIdentityModel(self::PAGE . '?utm_source=x', null, 'Story'),
            true,
        ];
        yield 'another landing, the same canonical' => [
            new PageIdentityModel('https://news.example/b', self::CANONICAL, 'Story'),
            true,
        ];
        yield 'another landing, another canonical' => [
            new PageIdentityModel('https://news.example/b', 'https://news.example/other', 'Story'),
            false,
        ];
        yield 'another landing, no canonical' => [
            new PageIdentityModel('https://news.example/b', null, 'Story'),
            false,
        ];
        yield 'the same landing, another title' => [new PageIdentityModel(self::PAGE, self::CANONICAL, 'Front'), false];
    }

    #[DataProvider('others')]
    public function testTwoPagesAreOneWhenTheirTitleAndTheirLandingOrCanonicalAgree(
        PageIdentityModel $other,
        bool $expected,
    ): void {
        self::assertSame($expected, new PageIdentityModel(self::PAGE, self::CANONICAL, 'Story')->isSamePageAs($other));
    }

    public function testTwoPagesWithoutCanonicalAreNotOneByThatAlone(): void
    {
        $page = new PageIdentityModel(self::PAGE, null, 'Story');

        self::assertFalse($page->isSamePageAs(new PageIdentityModel('https://news.example/b', null, 'Story')));
    }
}
