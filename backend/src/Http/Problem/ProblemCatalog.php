<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Exception\ApiException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final readonly class ProblemCatalog
{
    /** @param iterable<ExceptionProblems> $mappers */
    public function __construct(
        #[AutowireIterator('app.exception_problems')]
        private iterable $mappers,
        private LoggerInterface $logger,
        #[Autowire('%kernel.debug%')]
        private bool $debug,
    ) {
    }

    public function resolve(\Throwable $exception, string $path): ResolvedProblem
    {
        // Before the mappers, so none can claim one: a revoked or suspended token must stay the opaque 401.
        if ($exception instanceof AuthenticationException) {
            return self::unauthorized();
        }

        return $this->mapped($exception) ?? self::fromFramework($exception) ?? $this->unexpected($exception, $path);
    }

    private function mapped(\Throwable $exception): ?ResolvedProblem
    {
        foreach ($this->mappers as $mapper) {
            $resolved = $mapper->resolve($exception);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        return $exception instanceof ApiException ? self::legacy($exception) : null;
    }

    private static function fromFramework(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof HttpExceptionInterface
                => new ResolvedProblem(self::fromHttpException($exception), $exception->getHeaders()),
            $exception instanceof AccessDeniedException => new ResolvedProblem(new ApiProblem(
                'forbidden',
                'Forbidden',
                Response::HTTP_FORBIDDEN,
                'You do not have permission to access this resource.',
            )),
            default => null,
        };
    }

    private function unexpected(\Throwable $exception, string $path): ResolvedProblem
    {
        // The message may hold connection strings, tokens or row data: it goes to the log, never to the client.
        $this->logger->error('Unhandled API exception', ['exception' => $exception, 'path' => $path]);

        return new ResolvedProblem(new ApiProblem(
            'internal_error',
            'Internal server error',
            Response::HTTP_INTERNAL_SERVER_ERROR,
            $this->debug ? $exception->getMessage() : null,
        ));
    }

    private static function unauthorized(): ResolvedProblem
    {
        return new ResolvedProblem(new ApiProblem(
            'unauthorized',
            'Unauthorized',
            Response::HTTP_UNAUTHORIZED,
            'Authentication is required to access this resource.',
        ));
    }

    private static function fromHttpException(HttpExceptionInterface $exception): ApiProblem
    {
        $previous = $exception->getPrevious();
        // #[MapRequestPayload] reports constraint failures as a ValidationFailedException inside a 422.
        if ($previous instanceof ValidationFailedException) {
            return new ApiProblem(
                'validation_error',
                'Validation failed',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'One or more fields are invalid.',
                self::fieldErrors($previous),
            );
        }

        $status = $exception->getStatusCode();

        return new ApiProblem(
            match ($status) {
                Response::HTTP_UNAUTHORIZED => 'unauthorized',
                Response::HTTP_FORBIDDEN => 'forbidden',
                Response::HTTP_NOT_FOUND => 'not_found',
                Response::HTTP_METHOD_NOT_ALLOWED => 'method_not_allowed',
                Response::HTTP_TOO_MANY_REQUESTS => 'rate_limited',
                default => $status >= 500 ? 'internal_error' : 'request_error',
            },
            Response::$statusTexts[$status] ?? 'Error',
            $status,
        );
    }

    /** @return array<string, list<string>> */
    private static function fieldErrors(ValidationFailedException $failure): array
    {
        $errors = [];
        foreach ($failure->getViolations() as $violation) {
            $errors[$violation->getPropertyPath()][] = (string) $violation->getMessage();
        }

        return $errors;
    }

    private static function legacy(ApiException $exception): ResolvedProblem
    {
        return new ResolvedProblem(new ApiProblem(
            $exception->type,
            $exception->title,
            $exception->status,
            $exception->detail,
            $exception->errors,
        ));
    }
}
