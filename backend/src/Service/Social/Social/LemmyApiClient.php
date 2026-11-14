<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Service\Outbound\Fetch\GuardedApiClient;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Support\NetworkAnswer;
use Symfony\Component\HttpFoundation\Response;

final readonly class LemmyApiClient
{
    private const string NETWORK = 'The Lemmy instance';
    private const string REFUSED_SESSION = 'The Lemmy instance refused this session.';
    private const string NOT_FOUND = 'The Lemmy instance cannot find this post.';
    private const string BANNED = 'You are banned from this community.';
    private const string LOCKED = 'The post is locked.';
    private const string WRONG_PASSWORD = 'The Lemmy instance refused this username or password.';
    private const string NEEDS_TOTP = 'This Lemmy account needs its two-factor code.';
    private const string WRONG_TOTP = 'The Lemmy instance refused this two-factor code.';
    private const string UNVERIFIED_EMAIL = 'Confirm your email address on the Lemmy instance first.';
    private const string PENDING_APPLICATION = 'The Lemmy instance has not approved this account yet.';
    private const string NO_DOWNVOTES = 'This Lemmy instance does not allow downvotes.';

    public function __construct(private GuardedApiClient $api)
    {
    }

    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialPostRefusedException
     * @throws SocialPostNotFoundException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function send(ApiRequestModel $request): ApiResponseModel
    {
        $answer = $this->answer($request);
        if ($answer->isSuccessful()) {
            return $answer;
        }
        SocialNetworkThrottledException::throwIfThrottled($answer, 'The Lemmy instance asked for fewer requests.');
        $code = JsonNodeModel::of($answer->json())->string('error');
        throw self::failure($answer->status, $code);
    }

    /** @throws SocialNetworkUnreachableException only when no answer arrived; any status is returned */
    public function answer(ApiRequestModel $request): ApiResponseModel
    {
        return NetworkAnswer::orUnreachable(fn (): ApiResponseModel => $this->api->send($request), self::NETWORK);
    }

    private static function failure(int $status, ?string $code): \RuntimeException
    {
        return self::failureOfCode($code) ?? match ($status) {
            Response::HTTP_UNAUTHORIZED => new SocialCredentialsRejectedException(self::REFUSED_SESSION),
            Response::HTTP_NOT_FOUND => new SocialPostNotFoundException(self::NOT_FOUND),
            default => new SocialNetworkUnreachableException(sprintf('%s answered %d.', self::NETWORK, $status)),
        };
    }

    private static function failureOfCode(?string $code): ?\RuntimeException
    {
        return match ($code) {
            'incorrect_login' => new SocialCredentialsRejectedException(self::WRONG_PASSWORD),
            'missing_totp_token' => new SocialCredentialsRejectedException(self::NEEDS_TOTP),
            'incorrect_totp_token' => new SocialCredentialsRejectedException(self::WRONG_TOTP),
            'email_not_verified' => new SocialCredentialsRejectedException(self::UNVERIFIED_EMAIL),
            'registration_application_is_pending'
                => new SocialCredentialsRejectedException(self::PENDING_APPLICATION),
            'not_logged_in' => new SocialCredentialsRejectedException(self::REFUSED_SESSION),
            'downvotes_are_disabled' => new SocialPostRefusedException(self::NO_DOWNVOTES),
            'locked', 'post_is_locked' => new SocialPostRefusedException(self::LOCKED),
            'banned_from_community', 'person_is_banned_from_community', 'person_is_banned_from_site'
                => new SocialPostRefusedException(self::BANNED),
            'couldnt_find_object', 'couldnt_find_post', 'deleted' => new SocialPostNotFoundException(self::NOT_FOUND),
            'rate_limit_error' => new SocialNetworkThrottledException('The Lemmy instance asked for fewer requests.'),
            default => null,
        };
    }
}
