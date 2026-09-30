<?php

declare(strict_types=1);

namespace App\Service\Image;

use App\Service\Fetch\Exception\RedirectChainException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\Pass\LandedResponse;
use App\Service\Fetch\RedirectFollower;
use App\Service\Fetch\Support\HostKey;
use App\Service\Fetch\Support\ResponseHeader;
use App\Service\Image\Support\CookieHeader;
use App\Service\Image\Support\OriginRoot;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The Cookie header an image host hands a first visit to its homepage, for hosts that serve images only to a browser
 * that has been on the site (#475: oxmoxhh.de's `conz_bild`). One visit per host an hour, an empty answer included.
 */
final readonly class OriginCookies
{
    private const int MAX_REDIRECTS = 3;
    private const float TIMEOUT_SECONDS = 10.0;
    private const int LIFETIME_SECONDS = 3600;

    public function __construct(
        private RedirectFollower $redirects,
        #[Autowire(service: 'image_proxy.cookie.cache')]
        private CacheInterface $cache,
        private string $userAgent,
    ) {
    }

    public function for(string $imageUrl): string
    {
        $root = OriginRoot::of($imageUrl);

        return $this->cache->get(
            hash('xxh128', $root),
            function (ItemInterface $item) use ($root): string {
                $item->expiresAfter(self::LIFETIME_SECONDS);

                return $this->visit($root);
            },
        );
    }

    private function visit(string $root): string
    {
        try {
            $landed = $this->redirects->follow($root, $this->options(), self::MAX_REDIRECTS);
        } catch (RedirectChainException) {
            return '';
        }

        $cookies = $this->cookiesFor($landed, $root);
        $landed->response->cancel();

        return $cookies;
    }

    private function cookiesFor(LandedResponse $landed, string $root): string
    {
        if (!$landed->isSuccess() || HostKey::forUrl($landed->url) !== HostKey::forUrl($root)) {
            return '';
        }

        return CookieHeader::fromSetCookies(ResponseHeader::all($landed->response, 'set-cookie'));
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                'Accept-Encoding' => 'identity',
                'User-Agent' => $this->userAgent,
            ],
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS * 2,
            'on_progress' => static function (int $downloaded): void {
                ResponseTooLargeException::throwIfExceeded($downloaded);
            },
        ];
    }
}
