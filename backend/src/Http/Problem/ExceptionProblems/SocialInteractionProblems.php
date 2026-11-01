<?php

declare(strict_types=1);

namespace App\Http\Problem\ExceptionProblems;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ResolvedProblem;
use App\Service\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Exception\NotASocialPostException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Exception\SocialPostTooLongException;
use App\Service\Social\Exception\SocialTokenScopeMissingException;
use Symfony\Component\HttpFoundation\Response;

/** What acting on a network can end in. The throttled arm must stay above its parent, the unreachable one. */
final readonly class SocialInteractionProblems implements ExceptionProblemsInterface
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof NotASocialPostException => new ResolvedProblem(new ApiProblem(
                'social_post_unsupported',
                'Not a Bluesky or Mastodon post',
                Response::HTTP_CONFLICT,
                $exception->getMessage(),
            )),
            $exception instanceof NoSocialAccountForNetworkException => new ResolvedProblem(new ApiProblem(
                'social_account_missing',
                'No account on this network',
                Response::HTTP_CONFLICT,
                $exception->getMessage(),
            )),
            $exception instanceof SocialPostNotFoundException => new ResolvedProblem(new ApiProblem(
                'social_post_not_found',
                'Post no longer available',
                Response::HTTP_NOT_FOUND,
                $exception->getMessage(),
            )),
            $exception instanceof SocialPostRefusedException => new ResolvedProblem(new ApiProblem(
                'social_post_refused',
                'The network refused this action on the post',
                Response::HTTP_CONFLICT,
                $exception->getMessage(),
            )),
            $exception instanceof SocialPostTooLongException => new ResolvedProblem(new ApiProblem(
                'social_post_too_long',
                'The reply is too long for the network',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage(),
            )),
            $exception instanceof SocialTokenScopeMissingException => new ResolvedProblem(new ApiProblem(
                'social_token_scope_missing',
                "This account's token is missing a permission",
                Response::HTTP_CONFLICT,
                sprintf('Reconnect this account with an access token that has the %s scope.', $exception->missingScope),
            )),
            $exception instanceof SocialNetworkThrottledException => self::throttled($exception),
            $exception instanceof SocialNetworkUnreachableException => new ResolvedProblem(new ApiProblem(
                'social_network_unreachable',
                'The network could not be reached',
                Response::HTTP_BAD_GATEWAY,
                $exception->getMessage(),
            )),
            default => null,
        };
    }

    private static function throttled(SocialNetworkThrottledException $exception): ResolvedProblem
    {
        $retryAfter = $exception->retryAfterSeconds;

        return ResolvedProblem::expected(
            new ApiProblem(
                'social_network_throttled',
                'The network asked for fewer requests',
                Response::HTTP_SERVICE_UNAVAILABLE,
                $exception->getMessage(),
            ),
            null === $retryAfter ? [] : ['Retry-After' => (string) $retryAfter],
        );
    }
}
