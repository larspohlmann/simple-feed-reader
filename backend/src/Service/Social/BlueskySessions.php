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
        $answer = $this->ask($account, $path, $this->current($account));

        return self::isStale($answer) ? $this->ask($account, $path, $this->fresh($account)) : $answer;
    }

    public static function isStale(ApiResponseModel $answer): bool
    {
        return \in_array($answer->status, self::STALE_SESSION_STATUSES, true)
            && \in_array(JsonNodeModel::of($answer->json())->string('error'), self::STALE_SESSION_ERRORS, true);
    }

    private function ask(SocialAccount $account, string $path, BlueskySessionModel $session): ApiResponseModel
    {
        return $this->bluesky->answer(ApiRequestModel::get(
            $account->getServiceUrl() . $path,
            ['Authorization' => 'Bearer ' . $session->accessJwt],
        ));
    }
}
