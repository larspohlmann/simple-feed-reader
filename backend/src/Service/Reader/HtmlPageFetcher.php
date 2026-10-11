<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Fetch\Exception\RedirectChainException;
use App\Service\Fetch\Pass\LandedResponse;
use App\Service\Fetch\Pass\RedirectBudget;
use App\Service\Fetch\RedirectFollower;
use App\Service\Fetch\Support\ContentTypeCharset;
use App\Service\Html\Support\HtmlTranscoder;
use App\Service\Reader\Exception\PageFetchException;
use App\Service\Reader\Model\PageResponseModel;
use App\Service\Reader\StatusReasonPhrases\StatusReasonPhrasesInterface;
use App\Service\Text\Support\Whitespace;
use Symfony\Component\HttpClient\Response\StreamWrapper;
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
    private const int HEAD_MAX_BYTES = 524_288;
    private const int HEAD_CHUNK_BYTES = 8_192;
    private const string HEAD_END = '~</head|<body~i';
    // The transfer is aborted a little past the cap, so the bytes the read keeps have surely arrived.
    private const int HEAD_ABORT_BYTES = self::HEAD_MAX_BYTES + 65_536;
    private const float HEAD_TIMEOUT_SECONDS = 5.0;
    public const float HEAD_MAX_DURATION_SECONDS = 8.0;
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

    public function fetch(string $url): PageResponseModel
    {
        $remainingHops = self::MAX_REDIRECTS;
        $target = $url;
        while (true) {
            $landed = $this->land($target, $this->pageOptions(), new RedirectBudget($remainingHops));
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

    /**
     * The start of a page, for the metadata its head declares: reading stops once the head has ended and the
     * challenge scan's span has arrived, at 512 KB at most; the timeouts are short and a meta refresh is not chased.
     */
    public function fetchHead(string $url): PageResponseModel
    {
        $landed = $this->land(
            $url,
            $this->headOptions(),
            new RedirectBudget(self::MAX_REDIRECTS, self::HEAD_MAX_DURATION_SECONDS),
        );
        $this->assertSuccess($landed);
        $head = $this->utf8($this->firstBytes($landed), $landed);
        $this->assertNotChallenge(mb_substr($head, 0, self::LANDING_SCAN_LENGTH), $landed);

        return new PageResponseModel($landed->url, $head);
    }

    /** @param array<string, mixed> $options */
    private function land(string $url, array $options, RedirectBudget $budget): LandedResponse
    {
        try {
            return $this->redirects->followWithin($url, $options, $budget);
        } catch (RedirectChainException $exception) {
            throw new PageFetchException($exception->getMessage(), previous: $exception);
        }
    }

    private function readableBody(LandedResponse $landed): string
    {
        $this->assertSuccess($landed);
        $body = $this->content($landed);
        if (\strlen($body) > self::MAX_BYTES) {
            throw new PageFetchException(sprintf('response exceeds %d bytes', self::MAX_BYTES));
        }

        return $this->utf8($body, $landed);
    }

    private function assertSuccess(LandedResponse $landed): void
    {
        if ($landed->isSuccess()) {
            return;
        }
        $snippet = $this->errorBodySnippet($landed->response);
        $landed->response->cancel();
        $status = $this->describeStatus($landed->status);

        throw new PageFetchException($snippet === null ? $status : $status . ' — ' . $snippet);
    }

    /** The response stream reports a transport failure as a warning, which is turned into the failure it is. */
    private function firstBytes(LandedResponse $landed): string
    {
        $stream = StreamWrapper::createResource($landed->response);
        set_error_handler(static function (int $severity, string $message): never {
            throw new PageFetchException($message);
        });
        try {
            return self::readUntilTheHeadEnds($stream);
        } finally {
            restore_error_handler();
            $landed->response->cancel();
        }
    }

    /** @param resource $stream */
    private static function readUntilTheHeadEnds($stream): string
    {
        $head = '';
        $searchFrom = 0;
        while (!feof($stream) && \strlen($head) < self::HEAD_MAX_BYTES) {
            $chunk = fread($stream, min(self::HEAD_CHUNK_BYTES, self::HEAD_MAX_BYTES - \strlen($head)));
            if ($chunk === false) {
                throw new PageFetchException('the response could not be read');
            }
            $head .= $chunk;
            if (\strlen($head) < self::LANDING_SCAN_LENGTH) {
                continue;
            }
            if (preg_match(self::HEAD_END, $head, offset: $searchFrom) === 1) {
                break;
            }
            $searchFrom = \strlen($head) - \strlen('</head');
        }

        return $head;
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
    private function headOptions(): array
    {
        return [
            'headers' => $this->headers(),
            'timeout' => self::HEAD_TIMEOUT_SECONDS,
            'buffer' => false,
            'on_progress' => self::abortPastTheHead(),
        ];
    }

    /** @return array<string, mixed> */
    private function pageOptions(): array
    {
        return [
            'headers' => $this->headers(),
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS * 2,
            'on_progress' => static function (int $downloaded): void {
                if ($downloaded > self::MAX_BYTES) {
                    throw new PageFetchException(sprintf('response exceeds %d bytes', self::MAX_BYTES));
                }
            },
        ];
    }

    private static function abortPastTheHead(): \Closure
    {
        $aborted = false;

        return static function (int $downloaded) use (&$aborted): void {
            if ($aborted || $downloaded <= self::HEAD_ABORT_BYTES) {
                return;
            }
            $aborted = true;

            throw new PageFetchException(sprintf('head read past %d bytes', self::HEAD_ABORT_BYTES));
        };
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            // No transparent compression: on_progress would cap the compressed bytes while curl buffers the
            // inflated body whole, so a small gzip bomb could exhaust the worker's memory.
            'Accept-Encoding' => 'identity',
            'User-Agent' => $this->userAgent,
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
