<?php

declare(strict_types=1);

namespace App\Service\Image\GuardedImageProbe;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Pass\LandedResponse;
use App\Service\Fetch\Pass\RedirectBudget;
use App\Service\Fetch\RedirectFollower;
use App\Service\Image\Exception\GuardedImageUnavailableException;
use App\Service\Image\Model\ImageDimensionsModel;
use App\Service\Image\Support\MediaType;
use Symfony\Component\HttpClient\Response\StreamableInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/** Asks whether an image exists, or what size it is, without downloading it; over the same guarded chain. */
final readonly class GuardedImageProbe implements GuardedImageProbeInterface
{
    private const int TIMEOUT_SECONDS = 4;
    private const int MAX_REDIRECTS = 3;
    private const int HEADER_BYTES = 65_536;

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

    public function exists(string $imageUrl): bool
    {
        try {
            $landed = $this->redirects->followHead($imageUrl, $this->options([]), $this->budget());
        } catch (FetchException | ExceptionInterface) {
            return false;
        }
        if (\in_array($landed->status, self::HEAD_REFUSALS, true)) {
            return $this->measures($imageUrl);
        }

        return $landed->status === Response::HTTP_OK && self::declaresAnImage($landed);
    }

    public function measure(string $imageUrl): ImageDimensionsModel
    {
        try {
            $landed = $this->redirects->follow($imageUrl, $this->options(self::rangeHeader()), $this->budget());
            $bytes = $this->headerBytes($landed);
        } catch (FetchException | ExceptionInterface $exception) {
            throw new GuardedImageUnavailableException($exception->getMessage(), 0, $exception);
        }

        return ImageDimensionsModel::fromBytes($bytes)
            ?? throw new GuardedImageUnavailableException('No image header in the first bytes of ' . $imageUrl);
    }

    private function measures(string $imageUrl): bool
    {
        try {
            $this->measure($imageUrl);

            return true;
        } catch (GuardedImageUnavailableException) {
            return false;
        }
    }

    /** @throws ExceptionInterface */
    private function headerBytes(LandedResponse $landed): string
    {
        if (!\in_array($landed->status, self::MEASURABLE_STATUSES, true)) {
            throw new GuardedImageUnavailableException('Image responded ' . $landed->status . '.');
        }
        if (!$landed->response instanceof StreamableInterface) {
            throw new GuardedImageUnavailableException('Response cannot be read partially.');
        }
        $stream = $landed->response->toStream(false);
        $bytes = (string) stream_get_contents($stream, self::HEADER_BYTES);
        fclose($stream);
        $landed->response->cancel();

        return $bytes;
    }

    private static function declaresAnImage(LandedResponse $landed): bool
    {
        return str_starts_with(MediaType::of($landed->header('content-type')), 'image/');
    }

    /** @return array<string, string> */
    private static function rangeHeader(): array
    {
        return ['Range' => 'bytes=0-' . (self::HEADER_BYTES - 1)];
    }

    /**
     * @param array<string, string> $extraHeaders
     *
     * @return array<string, mixed>
     */
    private function options(array $extraHeaders): array
    {
        return [
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS,
            'headers' => ['Accept-Encoding' => 'identity', 'User-Agent' => $this->userAgent] + $extraHeaders,
        ];
    }

    private function budget(): RedirectBudget
    {
        return new RedirectBudget(self::MAX_REDIRECTS);
    }
}
