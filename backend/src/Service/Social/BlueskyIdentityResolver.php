<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Social\Exception\SocialAccountUnresolvableException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\BlueskyIdentityModel;

/** Finds the PDS that hosts a Bluesky account, so a self-hosted account signs in where it lives. */
final readonly class BlueskyIdentityResolver
{
    private const string RESOLVE_HANDLE = 'https://public.api.bsky.app/xrpc/com.atproto.identity.resolveHandle';
    private const string PLC_DIRECTORY = 'https://plc.directory/';
    private const string WEB_DID_PREFIX = 'did:web:';
    private const string PDS_SERVICE_SUFFIX = '#atproto_pds';

    public function __construct(private GuardedApiClient $api)
    {
    }

    /**
     * @throws SocialAccountUnresolvableException
     * @throws SocialNetworkUnreachableException
     */
    public function resolve(string $handleOrDid): BlueskyIdentityModel
    {
        $did = str_starts_with($handleOrDid, 'did:') ? $handleOrDid : $this->didOf($handleOrDid);

        return new BlueskyIdentityModel($did, $this->pdsOf($did));
    }

    private function didOf(string $handle): string
    {
        $answer = $this->call(ApiRequestModel::get(self::RESOLVE_HANDLE . '?handle=' . rawurlencode($handle)));
        $did = $answer->json()['did'] ?? null;

        if (!$answer->isSuccessful() || !\is_string($did)) {
            throw new SocialAccountUnresolvableException(sprintf('No Bluesky account has the handle "%s".', $handle));
        }

        return $did;
    }

    private function pdsOf(string $did): string
    {
        $answer = $this->call(ApiRequestModel::get($this->documentUrl($did)));
        $services = $answer->isSuccessful() ? ($answer->json()['service'] ?? []) : [];

        foreach (\is_array($services) ? $services : [] as $service) {
            if (self::isPds($service)) {
                return $service['serviceEndpoint'];
            }
        }

        throw new SocialAccountUnresolvableException(sprintf('The account %s names no PDS.', $did));
    }

    private function documentUrl(string $did): string
    {
        return str_starts_with($did, self::WEB_DID_PREFIX)
            ? 'https://' . substr($did, \strlen(self::WEB_DID_PREFIX)) . '/.well-known/did.json'
            : self::PLC_DIRECTORY . $did;
    }

    /** @phpstan-assert-if-true array{id: string, serviceEndpoint: string} $service */
    private static function isPds(mixed $service): bool
    {
        return \is_array($service)
            && \is_string($service['id'] ?? null)
            && str_ends_with($service['id'], self::PDS_SERVICE_SUFFIX)
            && \is_string($service['serviceEndpoint'] ?? null);
    }

    private function call(ApiRequestModel $request): ApiResponseModel
    {
        try {
            return $this->api->send($request);
        } catch (FetchException $exception) {
            throw new SocialNetworkUnreachableException('Bluesky could not be reached.', previous: $exception);
        }
    }
}
