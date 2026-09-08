<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Fetch\Exception\RedirectChainException;
use App\Service\Fetch\LandedResponse;
use App\Service\Fetch\RedirectFollower;
use App\Service\Reader\Exception\PageFetchException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

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
    private const int SNIPPET_LENGTH = 200;
    private const int SNIPPET_SCAN_LENGTH = 20_000;

    public function __construct(
        private RedirectFollower $redirects,
        private string $userAgent,
    ) {
    }

    public function fetch(string $url): PageResponse
    {
        $landed = $this->land($url);
        if (!$landed->isSuccess()) {
            $snippet = $this->errorBodySnippet($landed->response);
            $landed->response->cancel();
            $status = self::describeStatus($landed->status);

            throw new PageFetchException($snippet === null ? $status : $status . ' — ' . $snippet);
        }

        $body = $this->content($landed);
        if (\strlen($body) > self::MAX_BYTES) {
            throw new PageFetchException(sprintf('response exceeds %d bytes', self::MAX_BYTES));
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

    /** The status line as a reader would read it — the code with its standard
     *  reason phrase ("HTTP 403 Forbidden"), or the bare code for an unknown one. */
    private static function describeStatus(int $status): string
    {
        $phrase = Response::$statusTexts[$status] ?? '';

        return $phrase === '' ? sprintf('HTTP %d', $status) : sprintf('HTTP %d %s', $status, $phrase);
    }

    /** A short piece of the error page's visible text, to say why past the status
     *  code — a blocked request often explains itself in the body. Null when the
     *  body is unreadable or carries no text once its markup and scripts are gone. */
    private function errorBodySnippet(ResponseInterface $response): ?string
    {
        try {
            return self::visibleText($response->getContent(false));
        } catch (ExceptionInterface) {
            return null;
        }
    }

    private static function visibleText(string $html): ?string
    {
        // Only the head can survive the truncation below, so clean that much and
        // spare the regex passes a whole multi-megabyte error page.
        $head = mb_substr($html, 0, self::SNIPPET_SCAN_LENGTH);
        $withoutCode = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $head) ?? $head;
        $withoutTags = preg_replace('/<[^>]+>/', ' ', $withoutCode) ?? $withoutCode;
        $text = LeadingEngagementRules::collapse(
            html_entity_decode($withoutTags, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
        );
        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > self::SNIPPET_LENGTH
            ? rtrim(mb_substr($text, 0, self::SNIPPET_LENGTH)) . '…'
            : $text;
    }

    private function content(LandedResponse $landed): string
    {
        try {
            return $landed->response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw new PageFetchException($e->getMessage(), previous: $e);
        }
    }
}
