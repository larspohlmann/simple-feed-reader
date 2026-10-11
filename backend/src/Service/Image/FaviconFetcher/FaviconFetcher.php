<?php

declare(strict_types=1);

namespace App\Service\Image\FaviconFetcher;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\Model\ResponseSizeLimit;
use App\Service\Fetch\Pass\LandedResponse;
use App\Service\Fetch\Pass\RedirectBudget;
use App\Service\Fetch\RedirectFollower;
use App\Service\Image\Exception\FaviconRejectedException;
use App\Service\Image\Exception\FaviconUnavailableException;
use App\Service\Image\Model\FetchedFaviconModel;
use App\Service\Image\Support\ImageType;
use App\Service\Image\Support\MediaType;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Downloads one resolved icon URL over RedirectFollower's guarded chain, with a timeout, size caps, no compression
 * and an image content-type allow-list. Resolving a homepage to its icon URL is FaviconResolver's job.
 */
final readonly class FaviconFetcher implements FaviconFetcherInterface
{
    /** Also bounds digest article thumbnails; GdImageResizer's pixel cap is the real memory guard. */
    public const int MAX_BYTES = 3_145_728;

    private const int TIMEOUT_SECONDS = 8;
    private const int MAX_REDIRECTS = 3;

    /** Gone for good: retried and then dropped, unlike a merely-refused status. */
    private const array GONE_STATUSES = [Response::HTTP_NOT_FOUND, Response::HTTP_GONE];

    /** Formats a browser will render in an <img>. SVG is excluded deliberately:
     *  it is a script-carrying document format, and we serve these bytes back
     *  from our own origin. */
    private const array ALLOWED_TYPES = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'image/x-icon',
        'image/vnd.microsoft.icon',
    ];

    public function __construct(
        private RedirectFollower $redirects,
        private string $userAgent,
    ) {
    }

    public function download(string $iconUrl): FetchedFaviconModel
    {
        try {
            $landed = $this->redirects->follow($iconUrl, $this->options(), new RedirectBudget(self::MAX_REDIRECTS));
            [$bytes, $contentType] = $this->readSuccessfulResponse($landed);
        } catch (FetchException | TransportExceptionInterface $exception) {
            throw $this->asFetchFailure($exception);
        }

        $this->assertNotEmpty($bytes);
        $this->assertWithinSizeCap($bytes);

        return new FetchedFaviconModel($iconUrl, $bytes, $contentType);
    }

    /** A wire- or buffer-cap trip is a policy rejection, not a dead host. */
    private function asFetchFailure(\Throwable $exception): FaviconUnavailableException
    {
        if ($this->causedByOversizedResponse($exception)) {
            return new FaviconRejectedException($exception->getMessage(), 0, $exception);
        }

        return new FaviconUnavailableException($exception->getMessage(), 0, $exception);
    }

    private function causedByOversizedResponse(\Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof ResponseTooLargeException) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS,
            // No transparent compression, so the wire cap also bounds the buffered body: a compressed response
            // would otherwise decompress unbounded before the size check runs.
            'headers' => ['Accept-Encoding' => 'identity', 'User-Agent' => $this->userAgent],
            'on_progress' => static function (int $downloaded): void {
                ResponseTooLargeException::throwIfExceeded(ResponseSizeLimit::Download, $downloaded);
            },
        ];
    }

    /**
     * @return array{0: string, 1: string}
     * @throws TransportExceptionInterface
     */
    private function readSuccessfulResponse(LandedResponse $landed): array
    {
        $this->assertOk($landed->status);
        $declaredType = MediaType::of($landed->header('content-type'));
        $this->assertAllowedUnlessUndeclared($declaredType);
        $bytes = $landed->response->getContent();
        $contentType = ImageType::declaredOrSniffed($declaredType, $bytes);
        $this->assertAllowedType($contentType);

        return [$bytes, $contentType];
    }

    private function assertOk(int $status): void
    {
        if (Response::HTTP_OK === $status) {
            return;
        }
        if (
            $status >= Response::HTTP_BAD_REQUEST
            && $status < Response::HTTP_INTERNAL_SERVER_ERROR
            && !\in_array($status, self::GONE_STATUSES, true)
        ) {
            throw new FaviconRejectedException('Icon responded ' . $status . '.');
        }

        throw new FaviconUnavailableException('Icon responded ' . $status . '.');
    }

    private function assertNotEmpty(string $bytes): void
    {
        if ('' === $bytes) {
            throw new FaviconUnavailableException('Icon body was empty.');
        }
    }

    private function assertWithinSizeCap(string $bytes): void
    {
        if (\strlen($bytes) > self::MAX_BYTES) {
            throw new FaviconRejectedException('Icon exceeded ' . self::MAX_BYTES . ' bytes.');
        }
    }

    private function assertAllowedUnlessUndeclared(string $declaredType): void
    {
        if (!ImageType::isUndeclared($declaredType)) {
            $this->assertAllowedType($declaredType);
        }
    }

    private function assertAllowedType(string $type): void
    {
        if (\in_array($type, self::ALLOWED_TYPES, true)) {
            return;
        }
        $message = \sprintf('Content type "%s" is not an allowed image type.', $type);

        throw str_starts_with($type, 'image/')
            ? new FaviconRejectedException($message)
            : new FaviconUnavailableException($message);
    }
}
