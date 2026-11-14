<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Model\BlueskySessionModel;
use Symfony\Component\HttpFoundation\Response;

final readonly class BlueskySessions
{
    public const string SESSION_REFUSED = 'Bluesky refused the session of this account.';

    private const array STALE_SESSION_ERRORS = ['ExpiredToken', 'InvalidToken'];
    private const array STALE_SESSION_STATUSES = [Response::HTTP_BAD_REQUEST, Response::HTTP_UNAUTHORIZED];

    public function __construct(
        private BlueskyApiClient $bluesky,
        private BlueskySessionOpener $opener,
        private BlueskySessionCache $cache,
    ) {
    }

    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function current(SocialAccount $account): BlueskySessionModel
    {
        return $this->cache->find($account) ?? $this->fresh($account);
    }

    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function fresh(SocialAccount $account): BlueskySessionModel
    {
        $this->cache->forget($account);
        $session = $this->opener->openForAccount($account);
        $this->cache->remember($account, $session);

        return $session;
    }

    /**
     * A GET to the account's own service URL carrying its session's token, opening a session when none is cached.
     *
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function authorizedGet(SocialAccount $account, string $path): ApiRequestModel
    {
        return self::authorized(ApiRequestModel::get($account->getServiceUrl() . $path), $this->current($account));
    }

    public function forget(SocialAccount $account): void
    {
        $this->cache->forget($account);
    }

    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException only when no answer arrived; any status is returned
     */
    public function askAs(SocialAccount $account, string $path): ApiResponseModel
    {
        return $this->sendRetryingStale($account, ApiRequestModel::get($account->getServiceUrl() . $path));
    }

    /**
     * @param array<string, mixed> $json
     *
     * @throws SocialPostNotFoundException
     * @throws SocialPostRefusedException on a 400 that is not a stale session
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function actAs(SocialAccount $account, string $path, array $json): ApiResponseModel
    {
        $request = ApiRequestModel::postJson($account->getServiceUrl() . $path, $json);

        return self::actionAccepted($this->sendRetryingStale($account, $request));
    }

    /**
     * @throws SocialPostRefusedException on a 400 that is not a stale session
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function uploadAs(SocialAccount $account, string $path, string $bytes, string $contentType): ApiResponseModel
    {
        $request = ApiRequestModel::postBytes($account->getServiceUrl() . $path, $bytes, $contentType);

        return self::actionAccepted($this->sendRetryingStale($account, $request));
    }

    /**
     * @throws SocialCredentialsRejectedException when the session is refused even after the retry
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException on any other answer but a 2xx
     */
    public static function accepted(ApiResponseModel $answer): ApiResponseModel
    {
        if (Response::HTTP_UNAUTHORIZED === $answer->status || self::isStaleSession($answer)) {
            throw new SocialCredentialsRejectedException(self::SESSION_REFUSED);
        }

        return BlueskyApiClient::accepted($answer);
    }

    private static function actionAccepted(ApiResponseModel $answer): ApiResponseModel
    {
        if (Response::HTTP_NOT_FOUND === $answer->status) {
            throw new SocialPostNotFoundException('The post is no longer on Bluesky.');
        }
        if (Response::HTTP_BAD_REQUEST === $answer->status && !self::isStaleSession($answer)) {
            throw new SocialPostRefusedException('Bluesky refused this action on the post.');
        }

        return self::accepted($answer);
    }

    public static function isStaleSession(ApiResponseModel $answer): bool
    {
        return \in_array($answer->status, self::STALE_SESSION_STATUSES, true)
            && \in_array(JsonNodeModel::of($answer->json())->string('error'), self::STALE_SESSION_ERRORS, true);
    }

    private function sendRetryingStale(SocialAccount $account, ApiRequestModel $request): ApiResponseModel
    {
        $answer = $this->bluesky->answer(self::authorized($request, $this->current($account)));

        return self::isStaleSession($answer)
            ? $this->bluesky->answer(self::authorized($request, $this->fresh($account)))
            : $answer;
    }

    private static function authorized(ApiRequestModel $request, BlueskySessionModel $session): ApiRequestModel
    {
        return $request->withHeaders(['Authorization' => 'Bearer ' . $session->accessJwt]);
    }
}
