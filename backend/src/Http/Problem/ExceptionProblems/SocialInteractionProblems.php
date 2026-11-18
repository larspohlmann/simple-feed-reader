<?php

declare(strict_types=1);

namespace App\Http\Problem\ExceptionProblems;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ResolvedProblem;
use App\Service\Social\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Social\Exception\NotASocialPostException;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnavailableException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostCommunityMissingException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Exception\SocialPostRemovedException;
use App\Service\Social\Social\Exception\SocialPostTextMissingException;
use App\Service\Social\Social\Exception\SocialPostTitleTooShortException;
use App\Service\Social\Social\Exception\SocialPostTooLongException;
use App\Service\Social\Social\Exception\SocialTokenScopeMissingException;
use App\Service\Social\Social\Exception\SocialWriteOutcomeUnknownException;
use Symfony\Component\HttpFoundation\Response;

/** What acting on a network can end in. The throttled, unavailable and unknown-outcome arms must stay above their parent, the unreachable one. */
final readonly class SocialInteractionProblems implements ExceptionProblemsInterface
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof NotASocialPostException => new ResolvedProblem(new ApiProblem(
                'social_post_unsupported',
                'Not a post this reader can act on',
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
            $exception instanceof SocialPostRemovedException => new ResolvedProblem(new ApiProblem(
                'social_post_removed',
                'Post deleted on its network and removed here',
                Response::HTTP_GONE,
                $exception->getMessage(),
            )),
            $exception instanceof SocialPostRefusedException => new ResolvedProblem(new ApiProblem(
                'social_post_refused',
                'The network refused this action on the post',
                Response::HTTP_CONFLICT,
                $exception->getMessage(),
            )),
            $exception instanceof SocialPostTextMissingException => new ResolvedProblem(new ApiProblem(
                'social_post_text_missing',
                'The post needs some text',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage(),
            )),
            $exception instanceof SocialPostTitleTooShortException => new ResolvedProblem(new ApiProblem(
                'social_post_title_too_short',
                'The post title is too short',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage(),
            )),
            $exception instanceof SocialPostCommunityMissingException => new ResolvedProblem(new ApiProblem(
                'social_post_community_missing',
                'The post needs a community',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage(),
            )),
            $exception instanceof SocialPostTooLongException => new ResolvedProblem(new ApiProblem(
                'social_post_too_long',
                'The post is too long for the network',
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
            $exception instanceof SocialNetworkUnavailableException => new ResolvedProblem(
                new ApiProblem(
                    'social_network_unavailable',
                    'The network is not responding',
                    Response::HTTP_BAD_GATEWAY,
                    $exception->getMessage(),
                ),
                extensions: ['serviceName' => $exception->serviceName],
            ),
            $exception instanceof SocialWriteOutcomeUnknownException => new ResolvedProblem(
                new ApiProblem(
                    'social_write_outcome_unknown',
                    'The network may not have finished the request',
                    Response::HTTP_BAD_GATEWAY,
                    $exception->getMessage(),
                ),
                extensions: ['serviceName' => $exception->serviceName],
            ),
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
