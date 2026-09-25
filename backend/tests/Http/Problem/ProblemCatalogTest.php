<?php

declare(strict_types=1);

namespace App\Tests\Http\Problem;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ExceptionProblems;
use App\Http\Problem\ProblemCatalog;
use App\Http\Problem\ResolvedProblem;
use App\Security\AccountStatusException;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\InvalidTokenException as RevokedJwtException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class ProblemCatalogTest extends TestCase
{
    public function testTheMapperThatOwnsAnExceptionAnswersForItInAnyOrder(): void
    {
        $domain = self::problem('domain_failure');
        $overflow = self::problem('overflow_failure');
        $mappers = [
            self::claiming(\DomainException::class, $domain),
            self::claiming(\OverflowException::class, $overflow),
        ];

        foreach ([$mappers, array_reverse($mappers)] as $ordering) {
            $catalog = new ProblemCatalog($ordering, new NullLogger(), debug: false);

            self::assertSame($domain, $catalog->resolve(new \DomainException('x'), '/api/x'));
            self::assertSame($overflow, $catalog->resolve(new \OverflowException('x'), '/api/x'));
        }
    }

    public function testAnExceptionNoMapperClaimsIsALoggedOpaque500(): void
    {
        $exception = new \LogicException('DB password is hunter2');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'Unhandled API exception',
            ['exception' => $exception, 'path' => '/api/entries'],
        );
        $catalog = new ProblemCatalog(
            [self::claiming(\DomainException::class, self::problem('domain_failure'))],
            $logger,
            debug: false,
        );

        $resolved = $catalog->resolve($exception, '/api/entries');

        self::assertSame(
            ['type' => 'internal_error', 'title' => 'Internal server error', 'status' => 500],
            $resolved->problem->toArray(),
        );
        self::assertSame([], $resolved->headers);
        self::assertSame([], $resolved->extensions);
    }

    public function testDebugShowsTheMessageOfAnUnexpectedException(): void
    {
        $catalog = new ProblemCatalog([], new NullLogger(), debug: true);

        self::assertSame('boom', $catalog->resolve(new \LogicException('boom'), '/api/x')->problem->detail);
    }

    public function testNoMapperIsOfferedAnAuthenticationException(): void
    {
        $catalog = new ProblemCatalog(
            [self::claiming(\Throwable::class, self::problem('claimed_by_a_mapper'))],
            new NullLogger(),
            debug: false,
        );

        foreach ([new AccountStatusException('suspended'), new RevokedJwtException('revoked')] as $exception) {
            $resolved = $catalog->resolve($exception, '/api/me');

            self::assertSame(
                [
                    'type' => 'unauthorized',
                    'title' => 'Unauthorized',
                    'status' => 401,
                    'detail' => 'Authentication is required to access this resource.',
                ],
                $resolved->problem->toArray(),
            );
            self::assertSame([], $resolved->extensions);
        }
    }

    private static function problem(string $type): ResolvedProblem
    {
        return new ResolvedProblem(new ApiProblem($type, 'Title', 409));
    }

    /** @param class-string<\Throwable> $class */
    private static function claiming(string $class, ResolvedProblem $problem): ExceptionProblems
    {
        return new class ($class, $problem) implements ExceptionProblems {
            /** @param class-string<\Throwable> $class */
            public function __construct(private readonly string $class, private readonly ResolvedProblem $problem)
            {
            }

            public function resolve(\Throwable $exception): ?ResolvedProblem
            {
                return $exception instanceof $this->class ? $this->problem : null;
            }
        };
    }
}
