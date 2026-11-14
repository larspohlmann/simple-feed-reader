<?php

declare(strict_types=1);

namespace App\Service\Image;

use App\Service\Fetch\Exception\RedirectChainException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\Model\ResponseSizeLimit;
use App\Service\Fetch\Pass\LandedResponse;
use App\Service\Fetch\Pass\RedirectBudget;
use App\Service\Fetch\RedirectFollower;
use App\Service\Image\Exception\ImageRefusedException;
use App\Service\Image\Exception\ImageUnavailableException;
use App\Service\Image\Model\ImageRequestModel;
use App\Service\Image\Model\ProxiedImageModel;
use App\Service\Image\Support\ImageType;
use App\Service\Image\Support\MediaType;
use App\Service\Image\Support\OriginRoot;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/** Fetches one image for the proxy over RedirectFollower's guarded chain, served only as a raster type. */
final readonly class ImageDownloader
{
    private const int MAX_REDIRECTS = 3;
    private const float TIMEOUT_SECONDS = 10.0;
    private const array REFUSED_STATUSES = [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN];
    private const array SERVED_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];

    public function __construct(
        private RedirectFollower $redirects,
        private string $userAgent,
    ) {
    }

    public function download(ImageRequestModel $request): ProxiedImageModel
    {
        $landed = $this->land($request);
        $this->assertServed($landed);
        $declaredType = MediaType::of($landed->header('content-type'));
        $this->assertServedUnlessUndeclared($landed, $declaredType);
        $bytes = $this->body($landed);
        $contentType = ImageType::declaredOrSniffed($declaredType, $bytes);
        $this->assertServedType($landed, $contentType);

        return new ProxiedImageModel($bytes, $contentType);
    }

    private function land(ImageRequestModel $request): LandedResponse
    {
        try {
            return $this->redirects->follow($request->url, $this->options($request), $this->redirectBudget($request));
        } catch (RedirectChainException $exception) {
            throw new ImageUnavailableException($exception->getMessage(), previous: $exception);
        }
    }

    private function redirectBudget(ImageRequestModel $request): RedirectBudget
    {
        return new RedirectBudget($request->cookies === '' ? self::MAX_REDIRECTS : 0);
    }

    private function assertServed(LandedResponse $landed): void
    {
        if ($landed->isSuccess()) {
            return;
        }

        $landed->response->cancel();
        $message = sprintf('%s: HTTP %d', $landed->url, $landed->status);

        throw \in_array($landed->status, self::REFUSED_STATUSES, true)
            ? new ImageRefusedException($message)
            : new ImageUnavailableException($message);
    }

    private function assertServedUnlessUndeclared(LandedResponse $landed, string $declaredType): void
    {
        if (!ImageType::isUndeclared($declaredType)) {
            $this->assertServedType($landed, $declaredType);
        }
    }

    private function assertServedType(LandedResponse $landed, string $type): void
    {
        if (\in_array($type, self::SERVED_TYPES, true)) {
            return;
        }

        $landed->response->cancel();

        throw new ImageUnavailableException(sprintf('%s: "%s" is not a served image type', $landed->url, $type));
    }

    private function body(LandedResponse $landed): string
    {
        try {
            return $landed->response->getContent(false);
        } catch (ExceptionInterface $exception) {
            throw new ImageUnavailableException($exception->getMessage(), previous: $exception);
        }
    }

    /** @return array<string, mixed> */
    private function options(ImageRequestModel $request): array
    {
        $headers = [
            'Accept' => 'image/avif,image/webp,image/*;q=0.8',
            'Accept-Encoding' => 'identity',
            'Referer' => OriginRoot::of($request->url),
            'User-Agent' => $this->userAgent,
        ];
        if ($request->cookies !== '') {
            $headers['Cookie'] = $request->cookies;
        }

        return [
            'headers' => $headers,
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS * 2,
            'on_progress' => static function (int $downloaded): void {
                ResponseTooLargeException::throwIfExceeded(ResponseSizeLimit::Download, $downloaded);
            },
        ];
    }
}
