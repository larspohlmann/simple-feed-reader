<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Fetch\Exception\RedirectChainException;
use App\Service\Fetch\LandedResponse;
use App\Service\Fetch\RedirectFollower;
use App\Service\Reader\Exception\PageFetchException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * Retrieves an article's source HTML for reader-mode extraction: the guarded
 * redirect chain lives in RedirectFollower; this class negotiates HTML, caps the
 * body, and returns the decoded body plus the final URL (readability needs it
 * to resolve relative image URLs).
 */
final readonly class HtmlPageFetcher
{
    private const int MAX_REDIRECTS = 5;
    private const int MAX_BYTES = 3_000_000;
    private const float TIMEOUT_SECONDS = 10.0;

    public function __construct(
        private RedirectFollower $redirects,
        private string $userAgent,
    ) {
    }

    public function fetch(string $url): PageResponse
    {
        $landed = $this->land($url);
        if (!$landed->isSuccess()) {
            $landed->response->cancel();

            throw new PageFetchException(sprintf('%s: HTTP %d', $landed->url, $landed->status));
        }

        $body = $this->content($landed);
        if (\strlen($body) > self::MAX_BYTES) {
            throw new PageFetchException(sprintf('%s: response exceeds %d bytes', $landed->url, self::MAX_BYTES));
        }

        return new PageResponse($landed->url, $body);
    }

    private function land(string $url): LandedResponse
    {
        try {
            return $this->redirects->follow($url, $this->options(), self::MAX_REDIRECTS);
        } catch (RedirectChainException $e) {
            throw new PageFetchException($e->getMessage(), previous: $e);
        }
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                // Refuse transparent compression: otherwise curl counts the
                // COMPRESSED bytes against MAX_BYTES in on_progress but buffers
                // the DECOMPRESSED body whole before the post-read size check —
                // a small gzip bomb could inflate to GB and OOM the worker.
                'Accept-Encoding' => 'identity',
                'User-Agent' => $this->userAgent,
            ],
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS * 2,
            'on_progress' => static function (int $downloaded): void {
                if ($downloaded > self::MAX_BYTES) {
                    throw new PageFetchException(sprintf('response exceeds %d bytes', self::MAX_BYTES));
                }
            },
        ];
    }

    private function content(LandedResponse $landed): string
    {
        try {
            return $landed->response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw new PageFetchException(sprintf('%s: %s', $landed->url, $e->getMessage()), previous: $e);
        }
    }
}
