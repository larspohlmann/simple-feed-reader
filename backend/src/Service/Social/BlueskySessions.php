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
use App\Service\Social\Model\BlueskySessionModel;
use Symfony\Component\HttpFoundation\Response;

final readonly class BlueskySessions
{
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
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException only when no answer arrived; any status is returned
     */
    public function askAs(SocialAccount $account, string $path): ApiResponseModel
    {
        $url = $account->getServiceUrl() . $path;

        return $this->sendRetryingStale(
            $account,
            static fn (array $authorization): ApiRequestModel => ApiRequestModel::get($url, $authorization),
        );
    }

    /**
     * @param array<string, mixed> $json
     *
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException only when no answer arrived; any status is returned
     */
    public function postAs(SocialAccount $account, string $path, array $json): ApiResponseModel
    {
        $url = $account->getServiceUrl() . $path;

        return $this->sendRetryingStale(
            $account,
            static fn (array $authorization): ApiRequestModel => ApiRequestModel::postJson($url, $json, $authorization),
        );
    }

    /**
     * @throws SocialCredentialsRejectedException when the session is refused even after the retry
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException on any other answer but a 2xx
     */
    public static function accepted(ApiResponseModel $answer): ApiResponseModel
    {
        if (Response::HTTP_UNAUTHORIZED === $answer->status || self::isStale($answer)) {
            throw new SocialCredentialsRejectedException('Bluesky refused the session of this account.');
        }

        return BlueskyApiClient::accepted($answer);
    }

    public static function isStale(ApiResponseModel $answer): bool
    {
        return \in_array($answer->status, self::STALE_SESSION_STATUSES, true)
            && \in_array(JsonNodeModel::of($answer->json())->string('error'), self::STALE_SESSION_ERRORS, true);
    }

    /** @param \Closure(array<string, string>): ApiRequestModel $request */
    private function sendRetryingStale(SocialAccount $account, \Closure $request): ApiResponseModel
    {
        $answer = $this->bluesky->answer($request(self::authorization($this->current($account))));

        return self::isStale($answer)
            ? $this->bluesky->answer($request(self::authorization($this->fresh($account))))
            : $answer;
    }

    /** @return array<string, string> */
    private static function authorization(BlueskySessionModel $session): array
    {
        return ['Authorization' => 'Bearer ' . $session->accessJwt];
    }
}
