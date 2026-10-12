<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Social\ConnectBlueskyRequest;
use App\Dto\Social\ConnectMastodonRequest;
use App\Dto\Social\ReplaceCredentialRequest;
use App\Entity\User;
use App\Http\SocialAccountJson;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Social\SocialAccountConnector;
use App\Service\Social\SocialAccountEditor;
use App\Service\Social\SocialAccountForUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Every `{id}` resolves through SocialAccountForUser: another user's account answers 404. */
#[Route('/api/me/social-accounts')]
final readonly class SocialAccountController
{
    public function __construct(
        private SocialAccountConnector $connector,
        private SocialAccountEditor $editor,
        private SocialAccountForUser $socialAccount,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $socialAccountLimiter,
        private SocialAccountJson $accountJson,
    ) {
    }

    #[Route('', name: 'api_me_social_accounts_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse($this->accountJson->list($this->editor->accountsOf($user)));
    }

    #[Route('/bluesky', name: 'api_me_social_accounts_connect_bluesky', methods: ['POST'])]
    public function connectBluesky(
        #[CurrentUser] User $user,
        #[MapRequestPayload] ConnectBlueskyRequest $request,
    ): JsonResponse {
        $this->rateLimitGuard->enforceForUser($this->socialAccountLimiter, $user);
        $account = $this->connector->connect($user, $request->toCredentials());

        return new JsonResponse($this->accountJson->account($account), Response::HTTP_CREATED);
    }

    #[Route('/mastodon', name: 'api_me_social_accounts_connect_mastodon', methods: ['POST'])]
    public function connectMastodon(
        #[CurrentUser] User $user,
        #[MapRequestPayload] ConnectMastodonRequest $request,
    ): JsonResponse {
        $this->rateLimitGuard->enforceForUser($this->socialAccountLimiter, $user);
        $account = $this->connector->connect($user, $request->toCredentials());

        return new JsonResponse($this->accountJson->account($account), Response::HTTP_CREATED);
    }

    #[Route(
        '/{id}/credential',
        name: 'api_me_social_accounts_credential',
        requirements: ['id' => '\d+'],
        methods: ['PUT'],
    )]
    public function replaceCredential(
        #[CurrentUser] User $user,
        int $id,
        #[MapRequestPayload] ReplaceCredentialRequest $request,
    ): JsonResponse {
        $account = $this->socialAccount->require($user, $id);
        $this->rateLimitGuard->enforceForUser($this->socialAccountLimiter, $user);

        return new JsonResponse($this->accountJson->account($this->connector->reconnect($account, $request->secret)));
    }

    #[Route('/{id}/default', name: 'api_me_social_accounts_default', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function makeDefault(#[CurrentUser] User $user, int $id): JsonResponse
    {
        $this->editor->makeDefault($this->socialAccount->require($user, $id));

        return $this->list($user);
    }

    #[Route('/{id}', name: 'api_me_social_accounts_disconnect', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function disconnect(#[CurrentUser] User $user, int $id): JsonResponse
    {
        $this->editor->disconnect($this->socialAccount->require($user, $id));

        return $this->list($user);
    }
}
