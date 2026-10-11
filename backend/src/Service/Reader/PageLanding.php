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
use App\Service\Reader\StatusReasonPhrases\StatusReasonPhrasesInterface;
use App\Service\Text\Support\ClippedText;
use App\Service\Text\Support\Whitespace;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Lands a page request over RedirectFollower's guarded chain and judges what answered: a failed status, a challenge
 * interstitial, and a charset only the Content-Type header declares.
 */
final readonly class PageLanding
{
    public const int MAX_REDIRECTS = 5;
    public const int CHALLENGE_SCAN_LENGTH = 20_000;
    private const int SNIPPET_LENGTH = 200;
    private const int SNIPPET_SCAN_LENGTH = 20_000;

    public function __construct(
        private RedirectFollower $redirects,
        private LandingChallenge $challenge,
        private string $userAgent,
        private StatusReasonPhrasesInterface $reasonPhrases,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws PageFetchException
     */
    public function land(string $url, array $options, RedirectBudget $budget): LandedResponse
    {
        try {
            $landed = $this->redirects->follow($url, ['headers' => $this->headers()] + $options, $budget);
        } catch (RedirectChainException $exception) {
            throw new PageFetchException($exception->getMessage(), previous: $exception);
        }
        $this->assertSuccess($landed);

        return $landed;
    }

    public function utf8(string $body, LandedResponse $landed): string
    {
        $charset = ContentTypeCharset::of($landed->header('content-type'));

        return $charset === null ? $body : HtmlTranscoder::toUtf8($body, $charset);
    }

    /** @throws PageFetchException */
    public function assertNotChallenge(string $head, LandedResponse $landed): void
    {
        if ($this->challenge->matches($head)) {
            $landed->response->cancel();

            throw new PageFetchException(sprintf('%s: bot or consent challenge interstitial', $landed->url));
        }
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

        return ClippedText::trimmedOf($text, self::SNIPPET_LENGTH);
    }
}
