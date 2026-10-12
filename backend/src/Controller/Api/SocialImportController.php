<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Social\SocialImportRequest;
use App\Entity\User;
use App\Http\SocialImportJson;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Social\ImportCandidates;
use App\Service\Social\SocialAccountForUser;
use App\Service\Social\SocialImporter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/me/social-accounts')]
final readonly class SocialImportController
{
    public function __construct(
        private SocialAccountForUser $socialAccount,
        private ImportCandidates $importCandidates,
        private SocialImporter $importer,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $socialImportLimiter,
        private SocialImportJson $importJson,
    ) {
    }

    #[Route(
        '/{id}/import-candidates',
        name: 'api_me_social_accounts_import_candidates',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function candidates(#[CurrentUser] User $user, int $id): JsonResponse
    {
        $account = $this->socialAccount->requireConnected($user, $id);
        $this->rateLimitGuard->enforceForUser($this->socialImportLimiter, $user);

        return new JsonResponse($this->importJson->candidates($this->importCandidates->forAccount($account)));
    }

    #[Route('/{id}/import', name: 'api_me_social_accounts_import', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function import(
        #[CurrentUser] User $user,
        int $id,
        #[MapRequestPayload] SocialImportRequest $request,
    ): JsonResponse {
        $account = $this->socialAccount->require($user, $id);

        return new JsonResponse($this->importJson->result($this->importer->import($account, $request->toImport())));
    }
}
