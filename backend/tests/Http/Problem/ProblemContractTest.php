<?php

declare(strict_types=1);

namespace App\Tests\Http\Problem;

use App\EventListener\ApiExceptionListener;
use App\Exception\AccountNotActiveException;
use App\Exception\AlreadySubscribedException;
use App\Exception\FeedPreviewApiException;
use App\Exception\InvalidCredentialsException;
use App\Exception\InvalidOpmlException;
use App\Exception\InvalidSetupSecretException;
use App\Exception\InvalidTokenException;
use App\Exception\LastAdminException;
use App\Exception\OAuth\OAuthFailedException;
use App\Exception\OAuth\UnknownProviderException;
use App\Exception\RateLimitedException;
use App\Exception\ScrapingDisabledApiException;
use App\Exception\SetupUnavailableException;
use App\Exception\SubscriptionLimitReachedException;
use App\Exception\TagNameTakenException;
use App\Exception\ValidationException;
use App\Security\AccountStatusException;
use App\Service\Ai\Exception\AiKeyUnreadableException;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Ai\Exception\ConfigurationNotFoundException;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ModelNotOfferedException;
use App\Service\Ai\Exception\ModelRequiredForActivationException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Exception\TooManyConfigurationsException;
use App\Service\Backup\Exception\BackupDoesNotFitException;
use App\Service\Backup\Exception\BackupLoadFailedException;
use App\Service\Backup\Exception\InvalidBackupException;
use App\Service\Crypto\Exception\SecretUnreadableException;
use App\Service\Mail\Settings\Exception\IncompleteMailConfigurationException;
use App\Service\Passkey\Exception\AssertionRejectedException;
use App\Service\Passkey\Exception\AttestationRejectedException;
use App\Service\Passkey\Exception\DuplicatePasskeyException;
use App\Service\Passkey\Exception\LastSignInMethodException;
use App\Service\Passkey\Exception\PasskeyChallengeOwnershipException;
use App\Service\Passkey\Exception\PasskeyNotFoundException;
use App\Service\Passkey\Exception\PasskeySignInDisabledException;
use App\Service\Passkey\Exception\UnknownChallengeException;
use App\Service\Passkey\Exception\UnknownPasskeyCredentialException;
use App\Service\Recommendation\Exception\NoActiveRecommendationRunException;
use App\Service\Recommendation\Exception\NoResumableRecommendationRunException;
use App\Service\Recommendation\Exception\RecommendationRunActiveException;
use App\Service\Settings\Exception\RelyingPartyChangeRequiresConfirmationException;
use Doctrine\ORM\EntityNotFoundException;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\InvalidTokenException as RevokedJwtException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final class ProblemContractTest extends KernelTestCase
{
    /**
     * @param array<string, mixed>  $expectedBody
     * @param array<string, string> $expectedHeaders
     */
    #[DataProvider('deliberateFailures')]
    public function testADeliberateFailureRendersItsContract(
        \Throwable $exception,
        array $expectedBody,
        array $expectedHeaders = [],
    ): void {
        $response = $this->render($exception);

        self::assertSame($expectedBody['status'], $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame($expectedBody, $this->bodyOf($response));
        foreach ($expectedHeaders as $name => $value) {
            self::assertSame($value, $response->headers->get($name));
        }
    }

    #[DataProvider('unexpectedFailures')]
    public function testAnUnexpectedFailureStaysAnOpaque500(\Throwable $exception): void
    {
        $response = $this->render($exception);
        $body = $this->bodyOf($response);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('internal_error', $body['type']);
        self::assertSame('Internal server error', $body['title']);
    }

    /** @return iterable<string, array{0: \Throwable, 1: array<string, mixed>, 2?: array<string, string>}> */
    public static function deliberateFailures(): iterable
    {
        yield 'unknown passkey credential' => [
            new UnknownPasskeyCredentialException(),
            [
                'type' => 'unknown_passkey_credential',
                'title' => 'Unknown passkey',
                'status' => 401,
                'detail' => 'This passkey is not registered here.',
            ],
        ];
        yield 'unknown passkey challenge' => [
            new UnknownChallengeException(),
            ['type' => 'unknown_passkey_challenge', 'title' => 'Unknown or expired passkey challenge', 'status' => 400],
        ];
        yield 'passkey sign-in disabled' => [
            new PasskeySignInDisabledException(),
            [
                'type' => 'passkey_sign_in_disabled',
                'title' => 'Passkey sign-in is disabled',
                'status' => 403,
                'detail' => 'This instance has turned off passkey sign-in.',
            ],
        ];
        yield 'passkey not found' => [
            new PasskeyNotFoundException(),
            ['type' => 'passkey_not_found', 'title' => 'No such passkey', 'status' => 404],
        ];
        yield 'last sign-in method' => [
            new LastSignInMethodException(),
            [
                'type' => 'passkey_last_sign_in_method',
                'title' => 'Cannot remove your last sign-in method',
                'status' => 409,
                'detail' => 'This is your only way to sign in. Set a password or link a sign-in provider first.',
            ],
        ];
        yield 'duplicate passkey' => [
            new DuplicatePasskeyException(),
            [
                'type' => 'passkey_already_registered',
                'title' => 'Passkey already registered',
                'status' => 409,
                'detail' => 'This passkey is already registered.',
            ],
        ];
        yield 'passkey challenge owner mismatch' => [
            new PasskeyChallengeOwnershipException(),
            [
                'type' => 'passkey_challenge_owner_mismatch',
                'title' => 'Forbidden',
                'status' => 403,
                'detail' => 'This registration challenge was not issued to you.',
            ],
        ];
        yield 'attestation rejected, cause withheld' => [
            new AttestationRejectedException(new \RuntimeException('CBOR offset 17 in secret-attestation-bytes')),
            [
                'type' => 'passkey_attestation_rejected',
                'title' => 'Passkey registration rejected',
                'status' => 400,
                'detail' => 'The passkey could not be verified.',
            ],
        ];
        yield 'assertion rejected, cause withheld' => [
            new AssertionRejectedException(new \RuntimeException('secret-assertion-bytes')),
            [
                'type' => 'passkey_assertion_rejected',
                'title' => 'Passkey login rejected',
                'status' => 401,
                'detail' => 'The passkey could not be verified.',
            ],
        ];
        yield 'relying party change needs confirmation' => [
            new RelyingPartyChangeRequiresConfirmationException(3),
            [
                'type' => 'relying_party_change_requires_confirmation',
                'title' => 'Relying party change requires confirmation',
                'status' => 409,
                'detail' => 'Changing the passkey relying party id invalidates 3 enrolled passkey(s). '
                    . 'Resend the request with invalidateExistingPasskeys set to confirm.',
                'invalidatedPasskeyCount' => 3,
            ],
        ];
        yield 'incomplete mail configuration' => [
            IncompleteMailConfigurationException::passwordMissing(),
            [
                'type' => 'incomplete_mail_configuration',
                'title' => 'Incomplete mail configuration',
                'status' => 422,
                'detail' => 'An enabled SMTP transport with a username needs a password, stored or provided.',
            ],
        ];
        yield 'backup does not fit' => [
            new BackupDoesNotFitException('The backup holds 600 subscriptions; this account allows 500.'),
            [
                'type' => 'backup_does_not_fit',
                'title' => 'The backup does not fit this account',
                'status' => 409,
                'detail' => 'The backup holds 600 subscriptions; this account allows 500.',
            ],
        ];
        yield 'invalid backup' => [
            new InvalidBackupException('The file is not gzip-compressed.'),
            [
                'type' => 'invalid_backup',
                'title' => 'Invalid backup file',
                'status' => 422,
                'detail' => 'The file is not gzip-compressed.',
            ],
        ];
        yield 'backup load failed, driver message withheld' => [
            BackupLoadFailedException::from(new \RuntimeException('SQLSTATE[22001]: secret-column-value')),
            [
                'type' => 'backup_load_failed',
                'title' => 'The backup could not be loaded',
                'status' => 422,
                'detail' => 'The restore emptied the account and then could not load the file: '
                    . 'the database rejected one of its values. The account is now empty. '
                    . 'Correct or re-export the backup, then run the restore again.',
            ],
        ];
        yield 'backup entries load failed' => [
            BackupLoadFailedException::duringEntries(new \RuntimeException('secret-entry-value')),
            [
                'type' => 'backup_load_failed',
                'title' => 'The backup could not be loaded',
                'status' => 422,
                'detail' => 'A backup part could not be loaded: the database rejected one of its values. '
                    . 'The account was not emptied; correct or re-export the backup, then continue the restore.',
            ],
        ];
        yield 'rate limited' => [
            new RateLimitedException(120),
            [
                'type' => 'rate_limited',
                'title' => 'Too many requests',
                'status' => 429,
                'detail' => 'Too many attempts. Try again later.',
            ],
            ['Retry-After' => '120'],
        ];
        yield 'invalid credentials' => [
            new InvalidCredentialsException(),
            [
                'type' => 'invalid_credentials',
                'title' => 'Invalid credentials',
                'status' => 401,
                'detail' => 'Email address or password is incorrect.',
            ],
        ];
        yield 'invalid token' => [
            new InvalidTokenException(),
            [
                'type' => 'invalid_token',
                'title' => 'Invalid token',
                'status' => 400,
                'detail' => 'This link is invalid, already used, or expired.',
            ],
        ];
        yield 'invalid setup secret' => [
            new InvalidSetupSecretException(),
            [
                'type' => 'invalid_setup_secret',
                'title' => 'Forbidden',
                'status' => 403,
                'detail' => 'The setup secret is incorrect.',
            ],
        ];
        yield 'setup unavailable' => [
            new SetupUnavailableException(),
            [
                'type' => 'setup_unavailable',
                'title' => 'Not found',
                'status' => 404,
                'detail' => 'Setup is not available.',
            ],
        ];
        yield 'account pending verification' => [
            new AccountNotActiveException('pending_verification'),
            self::accountNotActive('pending_verification', 'Confirm your email address first.'),
        ];
        yield 'account pending approval' => [
            new AccountNotActiveException('pending_approval'),
            self::accountNotActive('pending_approval', 'An administrator has not approved this account yet.'),
        ];
        yield 'account suspended' => [
            new AccountNotActiveException('suspended'),
            self::accountNotActive('suspended', 'This account has been suspended.'),
        ];
        yield 'account rejected' => [
            new AccountNotActiveException('rejected'),
            self::accountNotActive('rejected', 'This account was rejected.'),
        ];
        yield 'account in any other status' => [
            new AccountNotActiveException('active'),
            self::accountNotActive('active', 'This account cannot sign in.'),
        ];
        yield 'invalid opml' => [
            new InvalidOpmlException('OPML has no <body>.'),
            [
                'type' => 'invalid_opml',
                'title' => 'The OPML document could not be parsed',
                'status' => 422,
                'detail' => 'OPML has no <body>.',
            ],
        ];
        yield 'last admin' => [
            new LastAdminException(),
            [
                'type' => 'last_admin',
                'title' => 'Last administrator',
                'status' => 409,
                'detail' => 'This is the only administrator account. Promote another account first.',
            ],
        ];
        yield 'subscription limit reached' => [
            new SubscriptionLimitReachedException(7),
            [
                'type' => 'subscription_limit_reached',
                'title' => 'Subscription limit reached',
                'status' => 409,
                'detail' => 'You can subscribe to at most 7 feeds.',
            ],
        ];
        yield 'already subscribed' => [
            new AlreadySubscribedException(),
            ['type' => 'already_subscribed', 'title' => 'Already subscribed to that feed', 'status' => 409],
        ];
        yield 'tag name taken' => [
            new TagNameTakenException(),
            ['type' => 'tag_name_taken', 'title' => 'Tag name already in use', 'status' => 409],
        ];
        yield 'validation' => [
            new ValidationException(['email' => ['Not a valid email address.']]),
            [
                'type' => 'validation_error',
                'title' => 'Validation failed',
                'status' => 422,
                'detail' => 'One or more fields are invalid.',
                'errors' => ['email' => ['Not a valid email address.']],
            ],
        ];
        yield 'unknown sign-in provider' => [
            new UnknownProviderException(),
            [
                'type' => 'unknown_provider',
                'title' => 'Unknown sign-in provider',
                'status' => 404,
                'detail' => 'That sign-in provider is not available.',
            ],
        ];
        yield 'oauth failed, log detail and cause withheld' => [
            new OAuthFailedException(
                'audience mismatch: token aud=attacker-client-id',
                new \RuntimeException('private key /etc/secrets/apple.p8 unreadable'),
            ),
            [
                'type' => 'oauth_failed',
                'title' => 'Sign-in failed',
                'status' => 502,
                'detail' => 'Signing in with that provider did not work. Please try again.',
            ],
        ];
        yield 'ai not configured' => [
            new AiNotConfiguredException('This account has no active AI configuration.'),
            [
                'type' => 'ai_not_configured',
                'title' => 'No AI provider is configured',
                'status' => 404,
                'detail' => 'Save an endpoint and an API key first.',
            ],
        ];
        yield 'ai configuration not found' => [
            new ConfigurationNotFoundException('No AI configuration 7 for this account.'),
            [
                'type' => 'ai_configuration_not_found',
                'title' => 'AI configuration not found',
                'status' => 404,
                'detail' => 'No such AI configuration for this account.',
            ],
        ];
        yield 'too many ai configurations' => [
            new TooManyConfigurationsException('This account already holds the maximum number of AI configurations.'),
            [
                'type' => 'ai_configuration_limit',
                'title' => 'Too many AI configurations',
                'status' => 409,
                'detail' => 'This account already holds the maximum number of AI configurations.',
            ],
        ];
        yield 'ai key unreadable' => [
            new AiKeyUnreadableException('The stored API key cannot be opened.'),
            [
                'type' => 'ai_key_unreadable',
                'title' => 'The stored API key could not be read',
                'status' => 422,
                'detail' => 'The stored API key can no longer be read. Enter it again.',
            ],
        ];
        yield 'ai provider unreachable' => [
            new ProviderUnreachableException('That address did not answer.'),
            self::providerRejected('That address did not answer.'),
        ];
        yield 'ai credentials rejected' => [
            new CredentialsRejectedException('That provider refused the API key.'),
            self::providerRejected('That provider refused the API key.'),
        ];
        yield 'ai model not offered' => [
            new ModelNotOfferedException('That provider does not offer "gpt-9".'),
            self::providerRejected('That provider does not offer "gpt-9".'),
        ];
        yield 'ai model required for activation' => [
            new ModelRequiredForActivationException('Choose a model before activating this configuration.'),
            self::providerRejected('Choose a model before activating this configuration.'),
        ];
        yield 'no active recommendation run' => [
            new NoActiveRecommendationRunException(),
            [
                'type' => 'no_active_recommendation_run',
                'title' => 'No recommendation run is active',
                'status' => 409,
                'detail' => 'There is nothing to stop: the run already finished.',
            ],
        ];
        yield 'no resumable recommendation run' => [
            new NoResumableRecommendationRunException('There is no failed run to resume.'),
            [
                'type' => 'no_resumable_recommendation_run',
                'title' => 'No recommendation run to resume',
                'status' => 409,
                'detail' => 'There is no failed run to resume; start a new one instead.',
            ],
        ];
        yield 'recommendation run active' => [
            new RecommendationRunActiveException(),
            [
                'type' => 'recommendation_run_active',
                'title' => 'A recommendation run is still active',
                'status' => 409,
                'detail' => 'Wait for the current run to finish, then try again.',
            ],
        ];
        yield 'scraping disabled' => [
            new ScrapingDisabledApiException('Website scraping is turned off for this account.'),
            [
                'type' => 'scraping_disabled',
                'title' => 'Website scraping is disabled',
                'status' => 403,
                'detail' => 'Website scraping is turned off for this account.',
            ],
        ];
        yield 'feed preview failed' => [
            new FeedPreviewApiException('The feed returned an empty document.'),
            [
                'type' => 'feed_preview_failed',
                'title' => 'Feed preview failed',
                'status' => 422,
                'detail' => 'The feed returned an empty document.',
            ],
        ];
        yield 'invalid catalog document, message withheld' => [
            new UnprocessableEntityHttpException('Duplicate feed URL "https://a.example/feed".'),
            ['type' => 'request_error', 'title' => 'Unprocessable Content', 'status' => 422],
        ];
        yield 'no comments feed, message withheld' => [
            new NotFoundHttpException('The entry has no comments feed.'),
            ['type' => 'not_found', 'title' => 'Not Found', 'status' => 404],
        ];
        yield 'http not found' => [
            new NotFoundHttpException(),
            ['type' => 'not_found', 'title' => 'Not Found', 'status' => 404],
        ];
        yield 'payload validation failure' => [
            new UnprocessableEntityHttpException('Validation failed', new ValidationFailedException(
                null,
                new ConstraintViolationList([
                    new ConstraintViolation('Not a valid email address.', null, [], null, 'email', 'nope'),
                    new ConstraintViolation(self::stringable('Too short.'), null, [], null, 'password', 'x'),
                ]),
            )),
            [
                'type' => 'validation_error',
                'title' => 'Validation failed',
                'status' => 422,
                'detail' => 'One or more fields are invalid.',
                'errors' => ['email' => ['Not a valid email address.'], 'password' => ['Too short.']],
            ],
        ];
        yield 'http 422, message withheld' => [
            new UnprocessableEntityHttpException('secret m'),
            ['type' => 'request_error', 'title' => 'Unprocessable Content', 'status' => 422],
        ];
        yield 'http 429 keeps retry-after' => [
            new TooManyRequestsHttpException(60),
            ['type' => 'rate_limited', 'title' => 'Too Many Requests', 'status' => 429],
            ['Retry-After' => '60'],
        ];
        yield 'http 405 keeps allow' => [
            new MethodNotAllowedHttpException(['GET', 'POST']),
            ['type' => 'method_not_allowed', 'title' => 'Method Not Allowed', 'status' => 405],
            ['Allow' => 'GET, POST'],
        ];
        yield 'http 401 keeps www-authenticate' => [
            new HttpException(401, 'Nope', null, ['Content-Type' => 'text/html', 'WWW-Authenticate' => 'Bearer']),
            ['type' => 'unauthorized', 'title' => 'Unauthorized', 'status' => 401],
            ['WWW-Authenticate' => 'Bearer'],
        ];
        yield 'http 500' => [
            new HttpException(500),
            ['type' => 'internal_error', 'title' => 'Internal Server Error', 'status' => 500],
        ];
        yield 'http 503' => [
            new ServiceUnavailableHttpException(),
            ['type' => 'internal_error', 'title' => 'Service Unavailable', 'status' => 503],
        ];
        yield 'http 400, message withheld' => [
            new BadRequestHttpException('secret request detail'),
            ['type' => 'request_error', 'title' => 'Bad Request', 'status' => 400],
        ];
        yield 'http 403' => [
            new AccessDeniedHttpException(),
            ['type' => 'forbidden', 'title' => 'Forbidden', 'status' => 403],
        ];
        yield 'authentication required' => [new AuthenticationException('Not authenticated.'), self::unauthorized()];
        yield 'bad credentials' => [new BadCredentialsException(), self::unauthorized()];
        yield 'account status stays opaque' => [new AccountStatusException('suspended'), self::unauthorized()];
        yield 'revoked jwt stays opaque' => [
            new RevokedJwtException('JWT predates the account\'s last password change.'),
            self::unauthorized(),
        ];
        yield 'security access denied' => [
            new AccessDeniedException(),
            [
                'type' => 'forbidden',
                'title' => 'Forbidden',
                'status' => 403,
                'detail' => 'You do not have permission to access this resource.',
            ],
        ];
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function unexpectedFailures(): iterable
    {
        yield 'logic error' => [new \LogicException('DB password is hunter2')];
        yield 'doctrine proxy miss' => [
            EntityNotFoundException::fromClassNameAndIdentifier('App\Entity\Tag', ['id' => '1']),
        ];
        yield 'unreadable secret outside ai' => [
            new SecretUnreadableException('The stored secret failed its integrity check.'),
        ];
    }

    /** @return array<string, mixed> */
    private static function accountNotActive(string $status, string $detail): array
    {
        return [
            'type' => 'account_not_active',
            'title' => 'Account not active',
            'status' => 403,
            'detail' => $detail,
            'accountStatus' => $status,
        ];
    }

    /** @return array<string, mixed> */
    private static function providerRejected(string $detail): array
    {
        return [
            'type' => 'ai_provider_rejected',
            'title' => 'The AI provider could not be used',
            'status' => 422,
            'detail' => $detail,
        ];
    }

    /** @return array<string, mixed> */
    private static function unauthorized(): array
    {
        return [
            'type' => 'unauthorized',
            'title' => 'Unauthorized',
            'status' => 401,
            'detail' => 'Authentication is required to access this resource.',
        ];
    }

    private static function stringable(string $text): \Stringable
    {
        return new class ($text) implements \Stringable {
            public function __construct(private readonly string $text)
            {
            }

            public function __toString(): string
            {
                return $this->text;
            }
        };
    }

    private function render(\Throwable $exception): Response
    {
        $event = new ExceptionEvent(
            self::bootKernel(),
            Request::create('/api/contract'),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );
        $listener = self::getContainer()->get(ApiExceptionListener::class);
        self::assertInstanceOf(ApiExceptionListener::class, $listener);
        $listener->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);

        return $response;
    }

    /** @return array<mixed> */
    private function bodyOf(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
