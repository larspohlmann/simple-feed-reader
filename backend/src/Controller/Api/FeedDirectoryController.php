<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\Factory\FeedDirectoryFileResponseFactory;
use App\Http\SubscribedFeedsJson;
use App\Service\FeedDirectory\FeedDirectoryFiles;
use App\Service\FeedDirectory\SubscribedFeeds;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/feed-directory')]
final readonly class FeedDirectoryController
{
    public function __construct(
        private FeedDirectoryFiles $files,
        private FeedDirectoryFileResponseFactory $responses,
        private SubscribedFeeds $subscribed,
    ) {
    }

    #[Route('/index', name: 'api_feed_directory_index', methods: ['GET'])]
    public function index(Request $request): BinaryFileResponse
    {
        return $this->responses->json($request, $this->files->indexPath());
    }

    #[Route('/countries/{code}', name: 'api_feed_directory_country', methods: ['GET'])]
    public function country(Request $request, string $code): BinaryFileResponse
    {
        return $this->responses->gzippedJson($request, $this->files->countryPath($code));
    }

    #[Route('/subscribed', name: 'api_feed_directory_subscribed', methods: ['GET'])]
    public function subscribed(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(SubscribedFeedsJson::of($this->subscribed->of($user->requireId())));
    }
}
