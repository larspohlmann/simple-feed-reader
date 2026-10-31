<?php

declare(strict_types=1);

namespace App\Tests\Http\Problem;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ExceptionProblems\ExceptionProblemsInterface;
use App\Http\Problem\ProblemCatalog;
use App\Http\Problem\ResolvedProblem;
use App\Security\AccountStatusException;
use App\Tests\Support\RecordingLogger;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\InvalidTokenException as RevokedJwtException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Response;

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
        $logger->expects($this->once())->method('error')->with(
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

    public function testAMappedServerSideProblemIsLogged(): void
    {
        $exception = new \DomainException('status.json is not valid JSON.');
        $logger = new RecordingLogger();

        $this->catalogMapping(self::problem('update_state_unreadable', Response::HTTP_INTERNAL_SERVER_ERROR), $logger)
            ->resolve($exception, '/api/admin/update');

        self::assertSame(
            [[
                'level' => LogLevel::ERROR,
                'message' => 'Server-side API problem',
                'context' => [
                    'exception' => $exception,
                    'path' => '/api/admin/update',
                    'type' => 'update_state_unreadable',
                ],
            ]],
            $logger->records,
        );
    }

    public function testAnUnavailabilityTheMapperDidNotExpectIsLogged(): void
    {
        $logger = new RecordingLogger();

        $this->catalogMapping(self::problem('mapped', Response::HTTP_SERVICE_UNAVAILABLE), $logger)
            ->resolve(new \DomainException('x'), '/api/x');

        self::assertCount(1, $logger->records);
    }

    /** @return iterable<string, array{ResolvedProblem}> */
    public static function unloggedProblems(): iterable
    {
        yield 'a client-side conflict' => [self::problem('mapped')];
        yield 'an expected unavailability' => [
            ResolvedProblem::expected(new ApiProblem('mapped', 'Title', Response::HTTP_SERVICE_UNAVAILABLE)),
        ];
    }

    #[DataProvider('unloggedProblems')]
    public function testAMappedProblemThatIsNoServerFaultIsNotLogged(ResolvedProblem $problem): void
    {
        $logger = new RecordingLogger();

        $this->catalogMapping($problem, $logger)->resolve(new \DomainException('x'), '/api/x');

        self::assertSame([], $logger->records);
    }

    private function catalogMapping(ResolvedProblem $problem, RecordingLogger $logger): ProblemCatalog
    {
        return new ProblemCatalog([self::claiming(\DomainException::class, $problem)], $logger, debug: false);
    }

    private static function problem(string $type, int $status = Response::HTTP_CONFLICT): ResolvedProblem
    {
        return new ResolvedProblem(new ApiProblem($type, 'Title', $status));
    }

    /** @param class-string<\Throwable> $class */
    private static function claiming(string $class, ResolvedProblem $problem): ExceptionProblemsInterface
    {
        return new class ($class, $problem) implements ExceptionProblemsInterface {
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
