<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\Support;

use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Parser\Support\DeclaredImages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeclaredImagesTest extends TestCase
{
    /** @return iterable<string, array{string, ?int}> */
    public static function dimensions(): iterable
    {
        yield 'a padded integer' => [' 640 ', 640];
        yield 'one pixel' => ['1', 1];
        yield 'zero' => ['0', null];
        yield 'a negative' => ['-5', null];
        yield 'not a number' => ['wide', null];
    }

    #[DataProvider('dimensions')]
    public function testADimensionIsAPositiveInteger(string $raw, ?int $expected): void
    {
        self::assertSame($expected, DeclaredImages::positiveDimension($raw));
    }

    public function testNoCandidatesHaveNoWidest(): void
    {
        self::assertNull(DeclaredImages::widest([]));
    }

    public function testALoneCandidateIsTheWidest(): void
    {
        $only = new DeclaredImageModel('https://i/only.jpg');

        self::assertSame($only, DeclaredImages::widest([$only]));
    }

    public function testTheFirstOfEquallyWideCandidatesWins(): void
    {
        $first = new DeclaredImageModel('https://i/first.jpg', 800);

        self::assertSame($first, DeclaredImages::widest([$first, new DeclaredImageModel('https://i/second.jpg', 800)]));
    }

    public function testWithoutDeclaredWidthsTheFirstCandidateWins(): void
    {
        $first = new DeclaredImageModel('https://i/first.jpg');

        self::assertSame($first, DeclaredImages::widest([$first, new DeclaredImageModel('https://i/second.jpg')]));
    }

    public function testAnyDeclaredWidthBeatsAnUndeclaredOne(): void
    {
        $undeclared = new DeclaredImageModel('https://i/unknown.jpg');
        $declared = new DeclaredImageModel('https://i/tiny.jpg', 1);

        self::assertSame($declared, DeclaredImages::widest([$undeclared, $declared]));
    }
}
