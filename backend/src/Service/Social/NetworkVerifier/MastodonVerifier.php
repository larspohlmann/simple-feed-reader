<?php

declare(strict_types=1);

namespace App\Service\Social\NetworkVerifier;

use App\Enum\SocialNetwork;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\SocialCredentialsModel;
use App\Service\Social\Model\VerifiedAccountModel;
use Symfony\Component\HttpFoundation\Response;

final readonly class MastodonVerifier implements NetworkVerifierInterface
{
    private const string VERIFY_CREDENTIALS = '/api/v1/accounts/verify_credentials';
    private const array REFUSALS = [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN];

    public function __construct(private GuardedApiClient $api)
    {
    }

    public static function network(): string
    {
        return SocialNetwork::Mastodon->value;
    }

    public function verify(SocialCredentialsModel $credentials): VerifiedAccountModel
    {
        try {
            $answer = $this->api->send(ApiRequestModel::get(
                $credentials->address . self::VERIFY_CREDENTIALS,
                ['Authorization' => 'Bearer ' . $credentials->secret],
            ));
        } catch (FetchException $exception) {
            throw new SocialNetworkUnreachableException(
                'The Mastodon instance could not be reached.',
                previous: $exception,
            );
        }

        if (\in_array($answer->status, self::REFUSALS, true)) {
            throw new SocialCredentialsRejectedException('The Mastodon instance refused this access token.');
        }

        $username = $answer->json()['username'] ?? null;
        if (!$answer->isSuccessful() || !\is_string($username)) {
            throw new SocialNetworkUnreachableException('The Mastodon instance gave no account for this token.');
        }

        $host = $this->hostOf($credentials->address);

        return new VerifiedAccountModel($username . '@' . $host, '@' . $username . '@' . $host, $credentials->address);
    }

    private function hostOf(string $origin): string
    {
        $port = parse_url($origin, \PHP_URL_PORT);

        return (string) parse_url($origin, \PHP_URL_HOST) . (null === $port ? '' : ':' . $port);
    }
}
