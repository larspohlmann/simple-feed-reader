<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Fetch\Exception\RedirectChainException;
use App\Service\Fetch\Pass\LandedResponse;
use App\Service\Fetch\RedirectFollower;
use App\Service\Fetch\Support\ContentTypeCharset;
use App\Service\Html\Support\HtmlTranscoder;
use App\Service\Reader\Exception\PageFetchException;
use App\Service\Reader\Model\PageResponseModel;
use App\Service\Reader\StatusReasonPhrases\StatusReasonPhrasesInterface;
use App\Service\Text\Support\Whitespace;
use OpenTelemetry\API\Instrumentation\WithSpan;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Fetches an article's HTML for extraction over RedirectFollower's guarded chain: negotiates HTML, caps the body and
 * returns it as UTF-8 with the final URL. A charset only the Content-Type header declares is transcoded here.
 */
final readonly class HtmlPageFetcher
{
    private const int MAX_REDIRECTS = 5;
    private const int MAX_BYTES = 3_000_000;
    private const float TIMEOUT_SECONDS = 10.0;
    private const int SNIPPET_LENGTH = 200;
    private const int SNIPPET_SCAN_LENGTH = 20_000;
    private const int LANDING_SCAN_LENGTH = 20_000;

    public function __construct(
        private RedirectFollower $redirects,
        private MetaRefreshTarget $metaRefresh,
        private LandingChallenge $challenge,
        private string $userAgent,
        private StatusReasonPhrasesInterface $reasonPhrases,
    ) {
    }

    #[WithSpan]
    public function fetch(string $url): PageResponseModel
    {
        $remainingHops = self::MAX_REDIRECTS;
        $target = $url;
        while (true) {
            $landed = $this->land($target, $remainingHops);
            $remainingHops -= $landed->hops;
            $body = $this->readableBody($landed);

            $head = mb_substr($body, 0, self::LANDING_SCAN_LENGTH);
            $this->assertNotChallenge($head, $landed);
            $next = $this->metaRefresh->within($head, $landed->url);
            if ($next === null) {
                return new PageResponseModel($landed->url, $body);
            }

            $landed->response->cancel();
            if ($remainingHops < 1) {
                throw new PageFetchException(sprintf('%s: more than %d redirects', $url, self::MAX_REDIRECTS));
            }
            $remainingHops--;
            $target = $next;
        }
    }

    private function land(string $url, int $maxRedirects): LandedResponse
    {
        try {
            return $this->redirects->follow($url, $this->options(), $maxRedirects);
        } catch (RedirectChainException $exception) {
            throw new PageFetchException($exception->getMessage(), previous: $exception);
        }
    }

    private function readableBody(LandedResponse $landed): string
    {
        if (!$landed->isSuccess()) {
            $snippet = $this->errorBodySnippet($landed->response);
            $landed->response->cancel();
            $status = $this->describeStatus($landed->status);

            throw new PageFetchException($snippet === null ? $status : $status . ' — ' . $snippet);
        }

        $body = $this->content($landed);
        if (\strlen($body) > self::MAX_BYTES) {
            throw new PageFetchException(sprintf('response exceeds %d bytes', self::MAX_BYTES));
        }

        return $this->utf8($body, $landed);
    }

    private function utf8(string $body, LandedResponse $landed): string
    {
        $charset = ContentTypeCharset::of($landed->header('content-type'));

        return $charset === null ? $body : HtmlTranscoder::toUtf8($body, $charset);
    }

    private function assertNotChallenge(string $head, LandedResponse $landed): void
    {
        if ($this->challenge->matches($head)) {
            $landed->response->cancel();

            throw new PageFetchException(sprintf('%s: bot or consent challenge interstitial', $landed->url));
        }
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                // No transparent compression: on_progress would cap the compressed bytes while curl buffers the
                // inflated body whole, so a small gzip bomb could exhaust the worker's memory.
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

    private function describeStatus(int $status): string
    {
        $phrase = $this->reasonPhrases->of($status);

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
        $text = Whitespace::collapse(
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
        } catch (ExceptionInterface $exception) {
            throw new PageFetchException($exception->getMessage(), previous: $exception);
        }
    }
}
