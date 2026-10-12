<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Service\Bluesky\AppViewClient;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Social\Exception\SocialAccountUnresolvableException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\BlueskyIdentityModel;
use App\Service\Social\Support\ProfileFeedUrl;
use App\Service\Url\Support\UrlOrigin;
use Symfony\Component\HttpFoundation\Response;

final readonly class BlueskyIdentityResolver
{
    private const string RESOLVE_HANDLE = AppViewClient::BASE_URL . 'com.atproto.identity.resolveHandle';
    private const string PLC_DIRECTORY = 'https://plc.directory/';
    private const string WEB_DID_PREFIX = 'did:web:';
    private const string PDS_SERVICE_SUFFIX = '#atproto_pds';

    public function __construct(private BlueskyApiClient $bluesky)
    {
    }

    /**
     * @throws SocialAccountUnresolvableException
     * @throws SocialNetworkUnreachableException
     */
    public function resolve(string $handleOrDid): BlueskyIdentityModel
    {
        $did = ProfileFeedUrl::isDid($handleOrDid) ? $handleOrDid : $this->didOf($handleOrDid);

        return new BlueskyIdentityModel($did, $this->pdsOf($did));
    }

    /**
     * @throws SocialAccountUnresolvableException
     * @throws SocialNetworkUnreachableException
     */
    public function didOf(string $handle): string
    {
        $answer = $this->bluesky->answer(
            ApiRequestModel::get(self::RESOLVE_HANDLE . '?handle=' . rawurlencode($handle)),
        );

        if (Response::HTTP_BAD_REQUEST === $answer->status) {
            throw new SocialAccountUnresolvableException(sprintf('No Bluesky account has the handle "%s".', $handle));
        }

        $did = $answer->isSuccessful() ? ($answer->json()['did'] ?? null) : null;
        if (!\is_string($did)) {
            throw new SocialNetworkUnreachableException('Bluesky gave no usable answer for the handle.');
        }

        return $did;
    }

    private function pdsOf(string $did): string
    {
        $answer = $this->bluesky->answer(ApiRequestModel::get($this->documentUrl($did)));

        if (Response::HTTP_NOT_FOUND === $answer->status) {
            throw new SocialAccountUnresolvableException(sprintf('The account %s has no DID document.', $did));
        }
        if (!$answer->isSuccessful()) {
            throw new SocialNetworkUnreachableException('Bluesky gave no usable DID document.');
        }

        $services = $answer->json()['service'] ?? [];
        foreach (\is_array($services) ? $services : [] as $service) {
            if (self::isPds($service)) {
                return $this->originOf($service['serviceEndpoint'], $did);
            }
        }

        throw new SocialAccountUnresolvableException(sprintf('The account %s names no PDS.', $did));
    }

    private function originOf(string $endpoint, string $did): string
    {
        $parts = parse_url($endpoint);

        if (false === $parts || 'https' !== strtolower($parts['scheme'] ?? '') || '' === ($parts['host'] ?? '')) {
            throw new SocialAccountUnresolvableException(sprintf('The PDS of %s is not reachable over https.', $did));
        }

        return UrlOrigin::fromParts('https', strtolower($parts['host']), $parts['port'] ?? null);
    }

    private function documentUrl(string $did): string
    {
        return str_starts_with($did, self::WEB_DID_PREFIX)
            ? 'https://' . self::webDidHost($did) . '/.well-known/did.json'
            : self::PLC_DIRECTORY . $did;
    }

    private static function webDidHost(string $did): string
    {
        $host = substr($did, \strlen(self::WEB_DID_PREFIX));
        if (str_contains($host, ':') || str_contains($host, '%')) {
            throw new SocialAccountUnresolvableException(
                sprintf('The account %s uses a did:web with a path or a port, which is not supported.', $did),
            );
        }

        return $host;
    }

    /** @phpstan-assert-if-true array{id: string, serviceEndpoint: string} $service */
    private static function isPds(mixed $service): bool
    {
        return \is_array($service)
            && \is_string($service['id'] ?? null)
            && str_ends_with($service['id'], self::PDS_SERVICE_SUFFIX)
            && \is_string($service['serviceEndpoint'] ?? null);
    }
}
