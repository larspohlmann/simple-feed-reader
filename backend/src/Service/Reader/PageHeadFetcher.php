<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Fetch\Pass\LandedResponse;
use App\Service\Fetch\Pass\RedirectBudget;
use App\Service\Image\Support\MediaType;
use App\Service\Reader\Exception\PageFetchException;
use App\Service\Reader\Model\PageHeadModel;
use Symfony\Component\HttpClient\Response\StreamWrapper;

/**
 * The start of a page, for the metadata its head declares: reading stops once the head has ended, at 1 MB at most
 * (a YouTube page declares its card past 760 KB); the timeouts are short and a meta refresh is not chased. A response
 * that declares a type other than markup is a file, and its body is not read.
 */
final readonly class PageHeadFetcher
{
    public const float MAX_DURATION_SECONDS = 8.0;
    private const float TIMEOUT_SECONDS = 5.0;
    private const int MAX_BYTES = 1_048_576;
    private const int CHUNK_BYTES = 8_192;
    // A body can carry a challenge marker, so the read runs at least as far as a whole page's challenge scan.
    private const int MIN_BYTES = PageLanding::CHALLENGE_SCAN_LENGTH;
    private const string HEAD_END = '~</head|<body~i';
    // The transfer is aborted a little past the cap, so the bytes the read keeps have surely arrived.
    private const int ABORT_BYTES = self::MAX_BYTES + 65_536;
    private const array MARKUP_TYPES = ['application/xhtml+xml', 'application/xml'];

    public function __construct(private PageLanding $landing)
    {
    }

    /** @throws PageFetchException */
    public function fetch(string $url): PageHeadModel
    {
        $landed = $this->landing->land(
            $url,
            self::options(),
            new RedirectBudget(PageLanding::MAX_REDIRECTS, self::MAX_DURATION_SECONDS),
        );
        $mediaType = MediaType::of($landed->header('content-type'));
        if (!self::isMarkup($mediaType)) {
            $landed->response->cancel();

            return PageHeadModel::ofFile($landed->url, $mediaType);
        }
        $head = $this->landing->utf8(self::firstBytes($landed), $landed);
        $this->landing->assertNotChallenge($head, $landed);

        return PageHeadModel::ofPage($landed->url, $head);
    }

    private static function isMarkup(string $mediaType): bool
    {
        return $mediaType === ''
            || str_starts_with($mediaType, 'text/')
            || \in_array($mediaType, self::MARKUP_TYPES, true);
    }

    /** The response stream reports a transport failure as a warning, which is turned into the failure it is. */
    private static function firstBytes(LandedResponse $landed): string
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
        while (!feof($stream) && \strlen($head) < self::MAX_BYTES) {
            $chunk = fread($stream, min(self::CHUNK_BYTES, self::MAX_BYTES - \strlen($head)));
            if ($chunk === false) {
                throw new PageFetchException('the response could not be read');
            }
            $head .= $chunk;
            if (\strlen($head) < self::MIN_BYTES) {
                continue;
            }
            if (preg_match(self::HEAD_END, $head, offset: $searchFrom) === 1) {
                break;
            }
            $searchFrom = \strlen($head) - \strlen('</head');
        }

        return $head;
    }

    /** @return array<string, mixed> */
    private static function options(): array
    {
        return [
            'timeout' => self::TIMEOUT_SECONDS,
            'buffer' => false,
            'on_progress' => self::abortPastTheHead(),
        ];
    }

    private static function abortPastTheHead(): \Closure
    {
        $aborted = false;

        return static function (int $downloaded) use (&$aborted): void {
            if ($aborted || $downloaded <= self::ABORT_BYTES) {
                return;
            }
            $aborted = true;

            throw new PageFetchException(sprintf('head read past %d bytes', self::ABORT_BYTES));
        };
    }
}
