<?php

declare(strict_types=1);

namespace App\Service\Content\Reader;

use App\Service\Outbound\Fetch\Exception\FetchException;
use App\Service\Outbound\Fetch\Exception\ResponseTooLargeException;
use App\Service\Outbound\Fetch\Model\ResponseSizeLimit;
use App\Service\Outbound\Fetch\Pass\LandedResponse;
use App\Service\Outbound\Fetch\Pass\RedirectBudget;
use App\Service\Outbound\Fetch\Support\PersistentConnection;
use App\Service\Outbound\Fetch\Support\ResponseBody;
use App\Service\Content\Reader\Exception\PageFetchException;
use App\Service\Content\Reader\Model\PageResponseModel;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * Fetches an article's whole HTML for extraction: caps the body, chases a zero-delay meta refresh on the redirect
 * budget, and returns the page as UTF-8 with the final URL.
 */
final readonly class HtmlPageFetcher
{
    private const float TIMEOUT_SECONDS = 10.0;

    public function __construct(
        private PageLanding $landing,
        private MetaRefreshTarget $metaRefresh,
    ) {
    }

    public function fetch(string $url): PageResponseModel
    {
        $budget = new RedirectBudget(PageLanding::MAX_REDIRECTS);
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
                throw new PageFetchException(sprintf('%s: more than %d redirects', $url, PageLanding::MAX_REDIRECTS));
            }
            $target = $next;
        }
    }

    private function readableBody(LandedResponse $landed): string
    {
        return $this->landing->utf8(self::content($landed), $landed);
    }

    /** @return array<string, mixed> */
    private static function options(): array
    {
        return [
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS * 2,
            ...PersistentConnection::options(),
            'headers' => ['Accept-Encoding' => ResponseBody::ACCEPT_ENCODING],
            'on_progress' => static function (int $downloaded): void {
                ResponseTooLargeException::throwIfExceeded(ResponseSizeLimit::Page, $downloaded);
            },
        ];
    }

    private static function content(LandedResponse $landed): string
    {
        try {
            return ResponseBody::decoded($landed->response, ResponseSizeLimit::Page);
        } catch (ExceptionInterface | FetchException $exception) {
            throw new PageFetchException($exception->getMessage(), previous: $exception);
        }
    }
}
