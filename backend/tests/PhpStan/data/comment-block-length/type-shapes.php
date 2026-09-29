<?php

declare(strict_types=1);

// Fixture for CommentBlockLengthRuleTest, excluded from `composer stan`; the line numbers matter.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Tests\PhpStan\Fixtures\CommentBlockLength;

/**
 * One.
 * Two.
 * Three.
 *
 * @phpstan-type Row array{
 *     id: int,
 *     title: string,
 *     tags: list<string>,
 *     scores: array<string, int|float>,
 * }
 * @template T of object
 */
final class TypeShapes
{
    /**
     * One.
     * Two.
     * Three.
     *
     * @param array{
     *     id: int,
     *     title: string,
     * } $row
     * @param class-string<T> $className
     * @param callable(int): string $format
     * @param int|null ...$ids
     *
     * @return array{
     *     id: int,
     *     title: string,
     *     summary: string|null,
     * }
     *
     * @throws \InvalidArgumentException
     */
    public function shapes(array $row, string $className, callable $format, ?int ...$ids): array
    {
        return ['id' => $row['id'], 'title' => $row['title'], 'summary' => null];
    }

    /**
     * One.
     * Two.
     *
     * @return array{
     *     id: int,
     * } Three: a description after the closing bracket is prose.
     * @throws \InvalidArgumentException Four: so is one after a thrown type.
     */
    public function describedShape(): array
    {
        return ['id' => 1];
    }
}

/**
 * One.
 * Two.
 * Three.
 *
 * @phpstan-import-type Row from TypeShapes
 * @method static ImportsARow create(int $id)
 */
final class ImportsARow
{
}
