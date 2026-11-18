<?php

declare(strict_types=1);

namespace App\Tests\Http\Problem;

use App\Http\Problem\ApiProblem;
use PHPUnit\Framework\TestCase;

final class ApiProblemTest extends TestCase
{
    public function testSerializesTheRequiredMembers(): void
    {
        $problem = new ApiProblem('validation_error', 'Validation failed', 422);

        self::assertSame([
            'type' => 'validation_error',
            'title' => 'Validation failed',
            'status' => 422,
        ], $problem->toArray());
    }

    public function testIncludesOptionalDetailAndErrors(): void
    {
        $problem = new ApiProblem(
            'validation_error',
            'Validation failed',
            422,
            'One or more fields are invalid.',
            ['email' => ['This value is not a valid email address.']],
        );

        self::assertSame([
            'type' => 'validation_error',
            'title' => 'Validation failed',
            'status' => 422,
            'detail' => 'One or more fields are invalid.',
            'errors' => ['email' => ['This value is not a valid email address.']],
        ], $problem->toArray());
    }

    public function testExtensionsAreMergedAfterTheStandardMembers(): void
    {
        $problem = new ApiProblem('t', 'Title', 502, 'Detail', extensions: ['instance' => 'lemmy.world']);

        self::assertSame([
            'type' => 't',
            'title' => 'Title',
            'status' => 502,
            'detail' => 'Detail',
            'instance' => 'lemmy.world',
        ], $problem->toArray());
    }
}
