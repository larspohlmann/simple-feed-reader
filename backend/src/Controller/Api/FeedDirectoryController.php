<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Http\Factory\FeedDirectoryFileResponseFactory;
use App\Service\Sourcing\FeedDirectory\FeedDirectoryFiles;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/feed-directory')]
final readonly class FeedDirectoryController
{
    public function __construct(
        private FeedDirectoryFiles $files,
        private FeedDirectoryFileResponseFactory $responses,
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
}
