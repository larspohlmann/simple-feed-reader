<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcher\FeedFetcherInterface;
use App\Service\Fetch\Model\FetchResponseModel;
use PHPUnit\Framework\Assert;

final class FakeAppView implements FeedFetcherInterface
{
    /** @var array<string, array<mixed>> */
    private array $postViews = [];

    private ?FetchException $failure = null;

    private ?string $body = null;

    /** @var list<list<string>> the URIs each request asked for, in order */
    public array $requests = [];

    public function knowsFixture(string $name): void
    {
        $answer = json_decode(Bluesky::fixture($name), true, flags: \JSON_THROW_ON_ERROR);
        Assert::assertIsArray($answer);
        $views = $answer['posts'] ?? null;
        Assert::assertIsArray($views);
        foreach ($views as $view) {
            Assert::assertIsArray($view);
            $uri = $view['uri'] ?? null;
            Assert::assertIsString($uri);
            $this->postViews[$uri] = $view;
        }
    }

    public function failsWith(FetchException $failure): void
    {
        $this->failure = $failure;
    }

    public function answersWithBody(string $body): void
    {
        $this->body = $body;
    }

    public function recovers(): void
    {
        $this->failure = null;
        $this->body = null;
    }

    public function fetch(string $url): FetchResponseModel
    {
        Assert::assertStringStartsWith(Bluesky::GET_POSTS . '?', $url);
        $uris = self::requestedUris($url);
        $this->requests[] = $uris;
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $body = $this->body ?? json_encode(['posts' => $this->knownViews($uris)], \JSON_THROW_ON_ERROR);

        return FetchResponseModel::fetched($url, false, $body, null, null);
    }

    /** @return list<string> */
    private static function requestedUris(string $url): array
    {
        preg_match_all('/[?&]uris=([^&]+)/', $url, $matches);

        return array_map(rawurldecode(...), $matches[1]);
    }

    /**
     * @param list<string> $uris
     *
     * @return list<array<mixed>>
     */
    private function knownViews(array $uris): array
    {
        $views = [];
        foreach ($uris as $uri) {
            if (isset($this->postViews[$uri])) {
                $views[] = $this->postViews[$uri];
            }
        }

        return $views;
    }
}
