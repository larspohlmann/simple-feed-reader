<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use App\Service\Fetch\Exception\RedirectChainException;
use App\Service\Fetch\RedirectFollower;

/** Where a media URL comes to rest: the 2xx landing of its redirect chain, or null when the chain fails or lands on an error. */
final readonly class MediaLanding
{
    private const int MAX_REDIRECTS = 5;
    private const float TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private RedirectFollower $redirects,
        private string $userAgent,
    ) {
    }

    public function urlOf(string $url): ?string
    {
        try {
            $landed = $this->redirects->follow($url, $this->options(), self::MAX_REDIRECTS);
        } catch (RedirectChainException) {
            return null;
        }
        $landed->response->cancel();

        return $landed->isSuccess() ? $landed->url : null;
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'headers' => [
                'Accept' => 'application/vnd.apple.mpegurl,application/x-mpegURL,*/*;q=0.8',
                'User-Agent' => $this->userAgent,
            ],
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS * 2,
        ];
    }
}
