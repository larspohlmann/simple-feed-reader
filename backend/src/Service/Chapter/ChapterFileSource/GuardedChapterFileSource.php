<?php

declare(strict_types=1);

namespace App\Service\Chapter\ChapterFileSource;

use App\Service\Chapter\Exception\ChapterFileUnreachableException;
use App\Service\Chapter\Support\JsonChapters;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Exception\SsrfBlockedException;
use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\Model\ApiRequestModel;

final readonly class GuardedChapterFileSource implements ChapterFileSourceInterface
{
    public function __construct(private GuardedApiClient $api)
    {
    }

    public function chaptersAt(string $url): array
    {
        try {
            $response = $this->api->send(ApiRequestModel::get($url));
        } catch (SsrfBlockedException) {
            return [];
        } catch (FetchException $exception) {
            throw ChapterFileUnreachableException::from($url, $exception);
        }

        return $response->isSuccessful() ? JsonChapters::from($response->json()) : [];
    }
}
