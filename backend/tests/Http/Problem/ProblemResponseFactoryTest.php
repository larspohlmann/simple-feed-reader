<?php

declare(strict_types=1);

namespace App\Tests\Http\Problem;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ProblemResponseFactory;
use App\Http\Problem\ResolvedProblem;
use PHPUnit\Framework\TestCase;

final class ProblemResponseFactoryTest extends TestCase
{
    public function testTheProblemContentTypeBeatsAPassThroughOneAndOtherHeadersSurvive(): void
    {
        $response = (new ProblemResponseFactory())->create(new ResolvedProblem(
            new ApiProblem('unauthorized', 'Unauthorized', 401),
            ['Content-Type' => 'text/html', 'WWW-Authenticate' => 'Bearer'],
        ));

        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame('Bearer', $response->headers->get('WWW-Authenticate'));
    }

    public function testExtensionMembersFollowTheProblemMembers(): void
    {
        $response = (new ProblemResponseFactory())->create(new ResolvedProblem(
            new ApiProblem('account_not_active', 'Account not active', 403, 'This account has been suspended.'),
            extensions: ['accountStatus' => 'suspended'],
        ));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(
            '{"type":"account_not_active","title":"Account not active","status":403,'
            . '"detail":"This account has been suspended.","accountStatus":"suspended"}',
            $response->getContent(),
        );
    }
}
