<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\Factory\BookmarkFileResponseFactory;
use App\Http\Factory\GoogleReaderStarredResponseFactory;
use App\Service\Library\SavedListExport\SavedListScopeResolver;
use App\Service\Library\SavedListExport\SavedListWalk;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/exports')]
final readonly class SavedListExportController
{
    public function __construct(
        private SavedListScopeResolver $scopeResolver,
        private SavedListWalk $walk,
        private BookmarkFileResponseFactory $bookmarkFileResponseFactory,
        private GoogleReaderStarredResponseFactory $googleReaderStarredResponseFactory,
    ) {
    }

    #[Route('/bookmarks', name: 'api_exports_bookmarks', methods: ['GET'])]
    public function bookmarks(
        #[CurrentUser] User $user,
        #[MapQueryParameter] ?string $view = null,
        #[MapQueryParameter] ?string $label = null,
        #[MapQueryParameter] ?string $order = null,
    ): StreamedResponse {
        $userId = $user->requireId();
        $scope = $this->scopeResolver->requireScope($userId, $view, $label, $order);

        return $this->bookmarkFileResponseFactory->stream($scope, $this->walk->batches($userId, $scope));
    }

    #[Route('/google-reader', name: 'api_exports_google_reader', methods: ['GET'])]
    public function googleReader(
        #[CurrentUser] User $user,
        #[MapQueryParameter] ?string $view = null,
        #[MapQueryParameter] ?string $label = null,
        #[MapQueryParameter] ?string $order = null,
    ): StreamedResponse {
        $userId = $user->requireId();
        $scope = $this->scopeResolver->requireScope($userId, $view, $label, $order);

        return $this->googleReaderStarredResponseFactory->stream($scope, $this->walk->batches($userId, $scope));
    }
}
