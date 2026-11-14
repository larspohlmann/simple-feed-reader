<?php

declare(strict_types=1);

namespace App\Service\Social\Social\NetworkVerifier;

use App\Enum\SocialNetwork;
use App\Service\Content\Comments\Support\LemmyAuthor;
use App\Service\Foundation\Url\Support\UrlOrigin;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\LemmyApiClient;
use App\Service\Social\Social\Model\LemmySessionModel;
use App\Service\Social\Social\Model\SocialCredentialsModel;
use App\Service\Social\Social\Model\VerifiedAccountModel;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Lemmy->value)]
final readonly class LemmyVerifier implements NetworkVerifierInterface
{
    private const string NO_SESSION = 'The Lemmy instance gave no session; confirm the account first.';

    private const string OTHER_INSTANCE = 'The Lemmy instance answered for an account on another instance.';

    public function __construct(private LemmyApiClient $lemmy)
    {
    }

    public function verify(SocialCredentialsModel $credentials): VerifiedAccountModel
    {
        $token = $this->login($credentials);
        $session = new LemmySessionModel($credentials->address, $token);
        $site = JsonNodeModel::of($this->lemmy->send($session->get('/api/v3/site'))->json());
        $person = $site->node('my_user')->node('local_user_view')->node('person');

        $actorId = $person->string('actor_id');
        $identity = LemmyAuthor::identity($person->string('name'), $actorId)
            ?? throw new SocialCredentialsRejectedException(self::NO_SESSION);
        if (!self::sameHost((string) $actorId, $credentials->address)) {
            throw new SocialCredentialsRejectedException(self::OTHER_INSTANCE);
        }

        return new VerifiedAccountModel($identity, '@' . $identity, $credentials->address, $token);
    }

    private function login(SocialCredentialsModel $credentials): string
    {
        $body = ['username_or_email' => $credentials->login, 'password' => $credentials->secret];
        if (null !== $credentials->secondFactor) {
            $body['totp_2fa_token'] = $credentials->secondFactor;
        }
        $answer = $this->lemmy->send(ApiRequestModel::postJson($credentials->address . '/api/v3/user/login', $body));

        return JsonNodeModel::of($answer->json())->string('jwt')
            ?? throw new SocialCredentialsRejectedException(self::NO_SESSION);
    }

    private static function sameHost(string $left, string $right): bool
    {
        $leftHost = (string) UrlOrigin::hostWithPort($left);

        return strtolower($leftHost) === strtolower((string) UrlOrigin::hostWithPort($right));
    }
}
