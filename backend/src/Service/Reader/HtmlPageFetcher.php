<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Fetch\Pass\LandedResponse;
use App\Service\Fetch\Pass\RedirectBudget;
use App\Service\Reader\Exception\PageFetchException;
use App\Service\Reader\Model\PageResponseModel;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * Fetches an article's whole HTML for extraction: caps the body, chases a zero-delay meta refresh on the redirect
 * budget, and returns the page as UTF-8 with the final URL.
 */
final readonly class HtmlPageFetcher
{
    private const int MAX_REDIRECTS = 5;
    private const int MAX_BYTES = 3_000_000;
    private const float TIMEOUT_SECONDS = 10.0;

    public function __construct(
        private PageLanding $landing,
        private MetaRefreshTarget $metaRefresh,
    ) {
    }

    public function fetch(string $url): PageResponseModel
    {
        $budget = new RedirectBudget(self::MAX_REDIRECTS);
        $target = $url;
        while (true) {
            $landed = $this->landing->land($target, self::options(), $budget);
            $body = $this->readableBody($landed);

            $head = mb_substr($body, 0, PageLanding::CHALLENGE_SCAN_LENGTH);
            $this->landing->assertNotChallenge($head, $landed);
            $next = $this->metaRefresh->within($head, $landed->url);
            if ($next === null) {
                return new PageResponseModel($landed->url, $body);
            }

            $landed->response->cancel();
            if (!$budget->takeRedirect()) {
                throw new PageFetchException(sprintf('%s: more than %d redirects', $url, self::MAX_REDIRECTS));
            }
            $target = $next;
        }
    }

    private function readableBody(LandedResponse $landed): string
    {
        $body = self::content($landed);
        if (\strlen($body) > self::MAX_BYTES) {
            throw new PageFetchException(sprintf('response exceeds %d bytes', self::MAX_BYTES));
        }

        return $this->landing->utf8($body, $landed);
    }

    /** @return array<string, mixed> */
    private static function options(): array
    {
        return [
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS * 2,
            'on_progress' => static function (int $downloaded): void {
                if ($downloaded > self::MAX_BYTES) {
                    throw new PageFetchException(sprintf('response exceeds %d bytes', self::MAX_BYTES));
                }
            },
        ];
    }

    private static function content(LandedResponse $landed): string
    {
        try {
            return $landed->response->getContent(false);
        } catch (ExceptionInterface $exception) {
            throw new PageFetchException($exception->getMessage(), previous: $exception);
        }
    }
}
