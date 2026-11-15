<?php

declare(strict_types=1);

namespace App\Service\Content\LinkResolution;

use App\Entity\LinkCardContent;
use App\Service\Content\LinkResolution\Model\LinkLandingModel;
use App\Service\Content\LinkResolution\Model\LinkResolutionBudgetModel;
use App\Service\Foundation\Url\UrlNormalizer;
use App\Service\Outbound\Fetch\Exception\RedirectChainException;
use App\Service\Outbound\Fetch\Pass\LandedResponse;
use App\Service\Outbound\Fetch\Pass\RedirectBudget;
use App\Service\Outbound\Fetch\RedirectFollower;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/** Follows a short link once, by HEAD and then GET, through the SSRF-guarded chain, reading no body. */
final readonly class LinkLanding
{
    private const int MAX_REDIRECTS = 5;

    public function __construct(
        private RedirectFollower $redirects,
        private UrlNormalizer $urlNormalizer,
        private string $userAgent,
    ) {
    }

    public function of(string $url): LinkLandingModel
    {
        try {
            $landed = $this->landingOf($url);
        } catch (RedirectChainException $exception) {
            return $this->outcomeOfFailure($url, $exception);
        }
        if ($landed->url === $url && self::isShortenerTrouble($landed->status)) {
            return LinkLandingModel::retryable();
        }

        return $this->outcomeOf($url, $landed->url);
    }

    /** @throws RedirectChainException when HEAD timed out or lost its connection, which a GET would only repeat */
    private function landingOf(string $url): LandedResponse
    {
        try {
            $head = $this->settled($this->redirects->followHead($url, $this->options(), self::budget()));
        } catch (RedirectChainException $exception) {
            return self::isTransient($exception) ? throw $exception : $this->landingByGet($url);
        }

        return $head->url !== $url || $head->isSuccess() ? $head : $this->landingByGet($url);
    }

    /** @throws RedirectChainException */
    private function landingByGet(string $url): LandedResponse
    {
        return $this->settled($this->redirects->follow($url, $this->options(), self::budget()));
    }

    /** A chain that reached a redirect target before it timed out points there, as a landing behind a 403 does. */
    private function outcomeOfFailure(string $url, RedirectChainException $exception): LinkLandingModel
    {
        if (!self::isTransient($exception)) {
            return LinkLandingModel::unresolved();
        }

        return $exception->hopUrl === null ? LinkLandingModel::retryable() : $this->outcomeOf($url, $exception->hopUrl);
    }

    private function outcomeOf(string $url, string $landingUrl): LinkLandingModel
    {
        $cleaned = (string) $this->urlNormalizer->withoutTracking($landingUrl);
        if (\strlen($cleaned) > LinkCardContent::URL_MAX) {
            return LinkLandingModel::unresolved();
        }
        $hash = $this->urlNormalizer->hash($cleaned);
        if ($hash === null || $hash === $this->urlNormalizer->hash($url)) {
            return LinkLandingModel::unchanged();
        }

        return LinkLandingModel::resolved($cleaned, $hash);
    }

    private function settled(LandedResponse $landed): LandedResponse
    {
        $landed->response->cancel();

        return $landed;
    }

    private static function isShortenerTrouble(int $status): bool
    {
        return $status === Response::HTTP_TOO_MANY_REQUESTS || $status >= Response::HTTP_INTERNAL_SERVER_ERROR;
    }

    private static function isTransient(RedirectChainException $exception): bool
    {
        for ($cause = $exception->getPrevious(); $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof TransportExceptionInterface) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'timeout' => LinkResolutionBudgetModel::ATTEMPT_SECONDS,
            'buffer' => false,
            'headers' => ['User-Agent' => $this->userAgent, 'Accept' => 'text/html,*/*;q=0.8'],
        ];
    }

    private static function budget(): RedirectBudget
    {
        return new RedirectBudget(self::MAX_REDIRECTS, LinkResolutionBudgetModel::ATTEMPT_SECONDS);
    }
}
