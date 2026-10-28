<?php

declare(strict_types=1);

namespace App\Service\Image\GuardedImageProbe;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\Model\ResponseSizeLimit;
use App\Service\Fetch\Pass\LandedResponse;
use App\Service\Fetch\Pass\RedirectBudget;
use App\Service\Fetch\RedirectFollower;
use App\Service\Image\Exception\GuardedImageUnavailableException;
use App\Service\Image\Model\ImageDimensionsModel;
use App\Service\Image\Support\MediaType;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/** Asks whether an image exists, or what size it is, without downloading it; over the same guarded chain. */
final readonly class GuardedImageProbe implements GuardedImageProbeInterface
{
    private const int TIMEOUT_SECONDS = 4;
    private const int MAX_REDIRECTS = 3;
    private const int HEADER_BYTES = 65_536;
    private const array RANGE_HEADERS = ['Range' => 'bytes=0-' . (self::HEADER_BYTES - 1)];

    private const array HEAD_REFUSALS = [
        Response::HTTP_FORBIDDEN,
        Response::HTTP_METHOD_NOT_ALLOWED,
        Response::HTTP_NOT_IMPLEMENTED,
    ];

    private const array MEASURABLE_STATUSES = [Response::HTTP_OK, Response::HTTP_PARTIAL_CONTENT];

    public function __construct(
        private RedirectFollower $redirects,
        private string $userAgent,
    ) {
    }

    public function exists(string $imageUrl, int $expectedWidth): bool
    {
        try {
            $landed = $this->redirects->followHead($imageUrl, $this->options(), $this->budget());
        } catch (FetchException | ExceptionInterface $exception) {
            throw GuardedImageUnavailableException::from($exception);
        }
        if (\in_array($landed->status, self::HEAD_REFUSALS, true)) {
            return $this->rangedGetMeasures($imageUrl, $expectedWidth);
        }

        return $landed->url === $imageUrl && $landed->status === Response::HTTP_OK && self::declaresAnImage($landed);
    }

    public function measure(string $imageUrl): ImageDimensionsModel
    {
        $landed = $this->rangedGet($imageUrl);
        if (!\in_array($landed->status, self::MEASURABLE_STATUSES, true)) {
            throw new GuardedImageUnavailableException('Image responded ' . $landed->status . '.');
        }

        return ImageDimensionsModel::fromBytes($this->headerBytes($landed))
            ?? throw new GuardedImageUnavailableException('No image header in the first bytes of ' . $imageUrl);
    }

    private function rangedGetMeasures(string $imageUrl, int $expectedWidth): bool
    {
        $landed = $this->rangedGet($imageUrl);
        if (!\in_array($landed->status, self::MEASURABLE_STATUSES, true)) {
            return false;
        }

        return ImageDimensionsModel::fromBytes($this->headerBytes($landed))?->width === $expectedWidth;
    }

    /** @throws GuardedImageUnavailableException */
    private function rangedGet(string $imageUrl): LandedResponse
    {
        try {
            return $this->redirects->follow($imageUrl, $this->options(self::RANGE_HEADERS), $this->budget());
        } catch (FetchException | ExceptionInterface $exception) {
            throw GuardedImageUnavailableException::from($exception);
        }
    }

    /** @throws GuardedImageUnavailableException */
    private function headerBytes(LandedResponse $landed): string
    {
        try {
            $bytes = $landed->response->getContent();
        } catch (FetchException | ExceptionInterface $exception) {
            throw GuardedImageUnavailableException::from($exception);
        }

        return substr($bytes, 0, self::HEADER_BYTES);
    }

    private static function declaresAnImage(LandedResponse $landed): bool
    {
        return str_starts_with(MediaType::of($landed->header('content-type')), 'image/');
    }

    /**
     * @param array<string, string> $extraHeaders
     *
     * @return array<string, mixed>
     */
    private function options(array $extraHeaders = []): array
    {
        return [
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS,
            'headers' => ['Accept-Encoding' => 'identity', 'User-Agent' => $this->userAgent] + $extraHeaders,
            'on_progress' => static function (int $downloaded): void {
                ResponseTooLargeException::throwIfExceeded(ResponseSizeLimit::Download, $downloaded);
            },
        ];
    }

    private function budget(): RedirectBudget
    {
        return new RedirectBudget(self::MAX_REDIRECTS, self::TIMEOUT_SECONDS);
    }
}
