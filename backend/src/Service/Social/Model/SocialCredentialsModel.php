<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Exception\InvalidInstanceUrlException;
use App\Service\Social\Exception\NotAnAppPasswordException;
use App\Service\Social\Support\ProfileFeedUrl;
use App\Service\Url\Support\UrlOrigin;

final readonly class SocialCredentialsModel
{
    private const int HINT_LENGTH = 4;
    private const string APP_PASSWORD = '/^[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}$/';

    private function __construct(
        public SocialNetwork $network,
        public string $address,
        public string $secret,
    ) {
    }

    /** @throws NotAnAppPasswordException */
    public static function bluesky(string $handle, string $appPassword): self
    {
        $address = trim($handle);
        if (!ProfileFeedUrl::isDid($address)) {
            $address = strtolower(ltrim($address, '@'));
        }

        return new self(SocialNetwork::Bluesky, $address, self::appPassword($appPassword));
    }

    /** @throws InvalidInstanceUrlException */
    public static function mastodon(string $instanceUrl, string $accessToken): self
    {
        return new self(SocialNetwork::Mastodon, self::instanceOrigin($instanceUrl), trim($accessToken));
    }

    /** @throws NotAnAppPasswordException */
    public static function forReconnect(SocialAccount $account, string $secret): self
    {
        return match ($account->getNetwork()) {
            SocialNetwork::Bluesky => new self(
                SocialNetwork::Bluesky,
                $account->getIdentity(),
                self::appPassword($secret),
            ),
            SocialNetwork::Mastodon => new self(SocialNetwork::Mastodon, $account->getServiceUrl(), trim($secret)),
        };
    }

    public function hint(): string
    {
        return substr($this->secret, -self::HINT_LENGTH);
    }

    /** @throws NotAnAppPasswordException */
    private static function appPassword(string $typed): string
    {
        $appPassword = trim($typed);
        if (1 !== preg_match(self::APP_PASSWORD, $appPassword)) {
            throw new NotAnAppPasswordException('Use an app password, not your account password.');
        }

        return $appPassword;
    }

    /** @throws InvalidInstanceUrlException */
    private static function instanceOrigin(string $instanceUrl): string
    {
        $typed = trim($instanceUrl);
        $parts = parse_url(str_contains($typed, '://') ? $typed : 'https://' . $typed);

        if (false === $parts || !isset($parts['host']) || '' === $parts['host']) {
            throw new InvalidInstanceUrlException(sprintf('"%s" names no instance.', $instanceUrl));
        }
        if ('https' !== strtolower($parts['scheme'] ?? '')) {
            throw new InvalidInstanceUrlException('An instance is reached over https only.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidInstanceUrlException('An instance URL carries no credentials.');
        }

        return UrlOrigin::fromParts('https', strtolower($parts['host']), $parts['port'] ?? null);
    }
}
