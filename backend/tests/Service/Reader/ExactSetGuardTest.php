<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\ExactSetGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class ExactSetGuardTest extends TestCase
{
    public function testAnExactPermutationIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();

        (new ExactSetGuard())->assertPermutation([3, 1, 2], [1, 2, 3], 'nope');
    }

    public function testAMissingIdIsRejected(): void
    {
        $this->assertRejected([1, 2], [1, 2, 3]);
    }

    public function testAnExtraIdIsRejected(): void
    {
        $this->assertRejected([1, 2, 3, 4], [1, 2, 3]);
    }

    public function testADuplicateIdIsRejected(): void
    {
        // Two ids, one repeated: same count as the owned set, so only the
        // uniqueness of the owned keys catches it once both are sorted.
        $this->assertRejected([1, 1], [1, 2]);
    }

    public function testTheMessageTravelsWithTheException(): void
    {
        try {
            (new ExactSetGuard())->assertPermutation([1], [1, 2], 'the exact message');
            $this->fail('Expected an UnprocessableEntityHttpException.');
        } catch (UnprocessableEntityHttpException $exception) {
            $this->assertSame('the exact message', $exception->getMessage());
        }
    }

    /**
     * @param list<int> $requested
     * @param list<int> $owned
     */
    private function assertRejected(array $requested, array $owned): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);

        (new ExactSetGuard())->assertPermutation($requested, $owned, 'rejected');
    }
}
