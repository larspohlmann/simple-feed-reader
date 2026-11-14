<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\NetworkVerifier;

use App\Service\Outbound\Fetch\IpValidator;
use App\Service\Outbound\Fetch\UrlGuard;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\LemmyApiClient;
use App\Service\Social\Social\Model\SocialCredentialsModel;
use App\Service\Social\Social\NetworkVerifier\LemmyVerifier;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\StaticDnsResolver;
use App\Tests\Support\TaggedItemOf;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LemmyVerifierTest extends TestCase
{
    private const string LOGIN = 'POST https://lemmy.example/api/v3/user/login';
    private const string SITE = 'GET https://lemmy.example/api/v3/site';
    private const string FIXTURES = __DIR__ . '/../../../../Fixtures/lemmy/';
    private const string TOKEN = 'test-jwt-not-a-real-token';

    public function testALoginVerifiesTheAccountAndKeepsTheToken(): void
    {
        $http = $this->http(self::fixture('login.json'), self::fixture('site-logged-in.json'));

        $account = $this->verifier($http)->verify($this->credentials());

        self::assertSame('lars@lemmy.example', $account->identity);
        self::assertSame('@lars@lemmy.example', $account->handle);
        self::assertSame('https://lemmy.example', $account->serviceUrl);
        self::assertSame(self::TOKEN, $account->credential);
        self::assertSame([self::LOGIN, self::SITE], $http->requestLines());
        self::assertSame('{"username_or_email":"lars","password":"pw"}', $http->requests[0]['body']);
        self::assertContains('Authorization: Bearer ' . self::TOKEN, $http->requests[1]['headers']);
    }

    public function testASecondFactorCodeRidesAlongOnTheLogin(): void
    {
        $http = $this->http(self::fixture('login.json'), self::fixture('site-logged-in.json'));

        $this->verifier($http)->verify(SocialCredentialsModel::lemmy('lemmy.example', 'lars', 'pw', '123456'));

        self::assertSame(
            '{"username_or_email":"lars","password":"pw","totp_2fa_token":"123456"}',
            $http->requests[0]['body'],
        );
    }

    public function testALoginWithoutATokenIsRefused(): void
    {
        $http = $this->http('{"jwt":null,"registration_created":false,"verify_email_sent":true}', '{}');

        $this->expectException(SocialCredentialsRejectedException::class);
        $this->expectExceptionMessage('The Lemmy instance gave no session; confirm the account first.');

        $this->verifier($http)->verify($this->credentials());
    }

    public function testASiteWithoutAUserRefusesTheSession(): void
    {
        $http = $this->http(self::fixture('login.json'), self::fixture('site-anonymous.json'));

        $this->expectException(SocialCredentialsRejectedException::class);

        $this->verifier($http)->verify($this->credentials());
    }

    public function testAnActorOnAnotherInstanceIsRefused(): void
    {
        $site = str_replace(
            'https://lemmy.example/u/lars',
            'https://other.example/u/lars',
            self::fixture('site-logged-in.json'),
        );
        $http = $this->http(self::fixture('login.json'), $site);

        $this->expectException(SocialCredentialsRejectedException::class);
        $this->expectExceptionMessage('The Lemmy instance answered for an account on another instance.');

        $this->verifier($http)->verify($this->credentials());
    }

    public function testTheActorHostIsComparedWithoutRegardToCase(): void
    {
        $site = str_replace(
            'https://lemmy.example/u/lars',
            'https://LEMMY.Example/u/lars',
            self::fixture('site-logged-in.json'),
        );

        $account = $this->verifier($this->http(self::fixture('login.json'), $site))->verify($this->credentials());

        self::assertSame('lars@lemmy.example', $account->identity);
    }

    public function testTheNetworkKeyIsLemmy(): void
    {
        self::assertSame('lemmy', TaggedItemOf::index(LemmyVerifier::class));
    }

    private function http(string $login, string $site): RoutedHttpClient
    {
        return new RoutedHttpClient([self::LOGIN => new MockResponse($login), self::SITE => new MockResponse($site)]);
    }

    private function verifier(RoutedHttpClient $http): LemmyVerifier
    {
        $guard = new UrlGuard(new StaticDnsResolver(['lemmy.example' => ['93.184.216.34']]), new IpValidator());

        return new LemmyVerifier(new LemmyApiClient(FetchWiring::guardedApiClient($http->client(), $guard)));
    }

    private function credentials(): SocialCredentialsModel
    {
        return SocialCredentialsModel::lemmy('lemmy.example', 'lars', 'pw', null);
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . $name);
    }
}
