<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\BlueskyIdentityModel;
use App\Service\Social\Model\BlueskySessionModel;
use Symfony\Component\HttpFoundation\Response;

final readonly class BlueskySessionOpener
{
    private const string CREATE_SESSION = '/xrpc/com.atproto.server.createSession';

    public function __construct(
        private GuardedApiClient $api,
        private StoredCredential $storedCredential,
    ) {
    }

    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkUnreachableException
     */
    public function openForAccount(SocialAccount $account): BlueskySessionModel
    {
        return $this->open(
            new BlueskyIdentityModel($account->getIdentity(), $account->getServiceUrl()),
            $this->storedCredential->open($account),
        );
    }

    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkUnreachableException
     */
    public function open(BlueskyIdentityModel $identity, string $appPassword): BlueskySessionModel
    {
        $answer = $this->createSession($identity, $appPassword);
        if (Response::HTTP_UNAUTHORIZED === $answer->status) {
            throw new SocialCredentialsRejectedException('Bluesky refused this handle and app password.');
        }

        $session = JsonNodeModel::of($answer->isSuccessful() ? $answer->json() : []);
        $did = $session->string('did');
        $handle = $session->string('handle');
        $accessJwt = $session->string('accessJwt');
        if (null === $did || null === $handle || null === $accessJwt) {
            throw new SocialNetworkUnreachableException('Bluesky gave no usable session.');
        }
        if ($did !== $identity->did) {
            throw new SocialCredentialsRejectedException('Bluesky opened a session for a different account.');
        }

        return new BlueskySessionModel($accessJwt, $handle, $identity->pdsUrl);
    }

    private function createSession(BlueskyIdentityModel $identity, string $appPassword): ApiResponseModel
    {
        try {
            return $this->api->send(ApiRequestModel::postJson(
                $identity->pdsUrl . self::CREATE_SESSION,
                ['identifier' => $identity->did, 'password' => $appPassword],
            ));
        } catch (FetchException $exception) {
            throw new SocialNetworkUnreachableException(
                'The Bluesky server could not be reached.',
                previous: $exception,
            );
        }
    }
}
